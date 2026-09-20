<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\Actions\CreateAcademicSource;
use App\Modules\Configuration\Application\Actions\DeleteAcademicSource;
use App\Modules\Configuration\Application\Actions\RequestAcademicSourceOcr;
use App\Modules\Configuration\Application\Actions\UpdateAcademicSource;
use App\Modules\Configuration\Application\Actions\UpdateAcademicSourceContent;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Presentation\Http\Requests\CreateSourceRequest;
use App\Modules\Configuration\Presentation\Http\Requests\IngestAcademicSourcePdfRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ManageAcademicSourceRequest;
use App\Modules\Configuration\Presentation\Http\Requests\UpdateSourceContentRequest;
use App\Modules\Configuration\Presentation\Http\Requests\UpdateSourceRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ViewSourcesRequest;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AcademicSourceController extends Controller
{
    public function index(ViewSourcesRequest $request, ActiveRole $roles, ProcessLocks $locks): JsonResponse
    {
        $activeRole = $roles->resolve($request);

        $payload = [
            'processLock' => $locks->careerLockReason($activeRole?->carrera_id),
            'sources' => AcademicSource::query()
                ->where('carrera_id', $activeRole?->carrera_id)
                ->where('activo', true)
                ->orderBy('nombre')
                ->with('latestExtraction')
                ->get()
                ->map(fn (AcademicSource $source) => [
                    'id' => $source->id,
                    'name' => $source->nombre,
                    'description' => $source->descripcion,
                    'has_content' => is_string($source->contenido) && trim($source->contenido) !== '',
                    'ingestion_status' => $source->estado_ingesta,
                    'extraction_id' => $source->latestExtraction?->id,
                ]),
        ];

        return response()->json($payload);
    }

    public function store(CreateSourceRequest $request, CreateAcademicSource $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $source = $action->execute($request->validated(), $actor, $request);

        return response()->json(['message' => 'Fuente creada. Ya puede redactar su contenido.', 'source_id' => $source->id], 201);
    }

    public function show(AcademicSource $source, ManageAcademicSourceRequest $request, ProcessLocks $locks): JsonResponse
    {
        $source->load('latestExtraction.execution');
        $payload = [
            'processLock' => $locks->careerLockReason($source->carrera_id),
            'source' => [
                'id' => $source->id,
                'name' => $source->nombre,
                'description' => $source->descripcion,
                'content' => $source->contenido,
                'ingestion' => [
                    'status' => $source->estado_ingesta,
                    'extraction_id' => $source->latestExtraction?->id,
                    'execution_id' => $source->latestExtraction?->ejecucion_trabajo_id,
                    'model' => $source->latestExtraction?->modelo_ocr,
                    'requested_at' => $source->latestExtraction?->solicitada_en?->toIso8601String(),
                    'completed_at' => $source->latestExtraction?->completada_en?->toIso8601String(),
                    'error_code' => $source->latestExtraction?->codigo_error,
                ],
            ],
        ];

        return response()->json($payload);
    }

    public function update(
        AcademicSource $source,
        UpdateSourceRequest $request,
        UpdateAcademicSource $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($source, $request->validated(), $actor, $request);

        $message = 'Fuente actualizada.';

        return response()->json(['message' => $message]);
    }

    public function destroy(
        AcademicSource $source,
        ManageAcademicSourceRequest $request,
        DeleteAcademicSource $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($source, $actor, $request);

        return response()->json(['message' => 'Fuente eliminada.']);
    }

    public function updateContent(
        AcademicSource $source,
        UpdateSourceContentRequest $request,
        UpdateAcademicSourceContent $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $content = $request->validated('content');
        $action->execute($source, is_string($content) ? $content : null, $actor, $request);

        $message = 'Contenido guardado.';

        return response()->json(['message' => $message]);
    }

    public function ingestPdf(
        AcademicSource $source,
        IngestAcademicSourcePdfRequest $request,
        RequestAcademicSourceOcr $action,
    ): JsonResponse {
        $actor = $request->user();
        $file = $request->file('pdf');
        abort_unless($actor instanceof User && $file instanceof UploadedFile, 422);
        $extraction = $action->execute($source, $file, $actor, $request);

        return response()->json([
            'message' => 'PDF recibido. La extracción Markdown se ejecutará en segundo plano.',
            'extraction_id' => $extraction->id,
            'execution_id' => $extraction->ejecucion_trabajo_id,
            'status' => $extraction->estado,
        ], 202);
    }

    public function asset(
        AcademicSource $source,
        string $asset,
        ManageAcademicSourceRequest $request,
    ): Response {
        $object = StoredObject::query()
            ->whereKey($asset)
            ->where('carrera_id', $source->carrera_id)
            ->where('clasificacion', 'academic_source_image')
            ->where('estado', 'activo')
            ->where('ruta_interna', 'like', "academic-sources/{$source->id}/assets/%")
            ->firstOrFail();

        return Storage::disk($object->disco)->response(
            $object->ruta_interna,
            $object->nombre_logico,
            [
                'Content-Type' => $object->mime,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
