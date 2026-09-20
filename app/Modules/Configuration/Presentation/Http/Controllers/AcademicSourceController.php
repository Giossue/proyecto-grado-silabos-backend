<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\AcademicSourceDocument;
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
                ->with('latestExecution')
                ->get()
                ->map(fn (AcademicSource $source) => [
                    'id' => $source->id,
                    'name' => $source->nombre,
                    'description' => $source->descripcion,
                    'has_content' => ($source->documento['content'] ?? []) !== [],
                    'ingestion_status' => $source->estado_ingesta,
                    'execution_id' => $source->latestExecution?->id,
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

    public function show(
        AcademicSource $source,
        ManageAcademicSourceRequest $request,
        ProcessLocks $locks,
        AcademicSourceDocument $documents,
    ): JsonResponse {
        $source->load('latestExecution');
        $execution = $source->latestExecution;
        $payload = [
            'processLock' => $locks->careerLockReason($source->carrera_id),
            'source' => [
                'id' => $source->id,
                'name' => $source->nombre,
                'description' => $source->descripcion,
                'document' => $documents->withAssetUrls($source, $source->documento ?? ['schemaVersion' => 1, 'type' => 'doc', 'content' => []]),
                'document_schema_version' => $source->version_documento,
                'has_original_pdf' => $source->objeto_pdf_id !== null,
                'ingestion' => [
                    'status' => $source->estado_ingesta,
                    'execution_id' => $execution?->id,
                    'model' => is_string($execution?->entrada['model'] ?? null) ? $execution->entrada['model'] : null,
                    'requested_at' => $execution?->encolado_en?->toIso8601String(),
                    'completed_at' => $execution?->finalizado_en?->toIso8601String(),
                    'error_code' => $execution?->codigo_error,
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
        AcademicSourceDocument $documents,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $document = $request->validated('document');
        if (! is_array($document)) {
            $content = $request->validated('content');
            $document = $documents->fromLegacyText(is_string($content) ? $content : null);
        }
        $action->execute($source, $document, $actor, $request);

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
        $execution = $action->execute($source, $file, $actor, $request);

        return response()->json([
            'message' => 'PDF recibido. OpenParser estructurará el documento en segundo plano.',
            'execution_id' => $execution->id,
            'status' => $execution->estado,
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

    public function originalPdf(
        AcademicSource $source,
        ManageAcademicSourceRequest $request,
    ): Response {
        $object = StoredObject::query()
            ->whereKey($source->objeto_pdf_id)
            ->where('carrera_id', $source->carrera_id)
            ->where('clasificacion', 'academic_source_pdf')
            ->where('estado', 'activo')
            ->where('mime', 'application/pdf')
            ->where('ruta_interna', 'like', "academic-sources/{$source->id}/%")
            ->firstOrFail();

        return Storage::disk($object->disco)->response(
            $object->ruta_interna,
            $object->nombre_logico,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.addcslashes($object->nombre_logico, '"\\').'"',
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
