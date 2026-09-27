<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\RequestCurricularImport;
use App\Modules\Academic\Application\Actions\RetryCurricularImport;
use App\Modules\Academic\Application\Queries\CurricularImportsViewData;
use App\Modules\Academic\Presentation\Http\Requests\RequestCurricularImportRequest;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CurricularImportUploadController extends Controller
{
    public function original(Request $request, string $import, string $source, CurricularImportsViewData $view): StreamedResponse
    {
        $record = $view->find($import, $request);
        $document = collect($record->documentos_importacion)->firstWhere('source_id', $source);
        abort_unless(is_array($document) && is_string($document['original_object_id'] ?? null), 404);
        $object = StoredObject::query()->where('carrera_id', $record->carrera_id)->where('estado', 'activo')
            ->whereKey($document['original_object_id'])->firstOrFail();
        abort_unless(Storage::disk($object->disco)->exists($object->ruta_interna), 404);

        return Storage::disk($object->disco)->download($object->ruta_interna, $object->nombre_logico, [
            'Content-Type' => $object->mime, 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function retry(Request $request, string $import, RetryCurricularImport $action, CurricularImportsViewData $view): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json($view->detail($action->execute($import, $actor, $request)), 202);
    }

    public function store(RequestCurricularImportRequest $request, RequestCurricularImport $action, CurricularImportsViewData $view): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $files = $request->file('files', []);
        abort_unless(is_array($files), 422);
        $import = $action->execute(array_values($files), $request->validated('source_ids', []), $request->validated('instruction'), $actor, $request);

        return response()->json($view->detail($import), 202);
    }
}
