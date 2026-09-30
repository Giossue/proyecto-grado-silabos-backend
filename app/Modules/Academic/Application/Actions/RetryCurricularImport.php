<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\CurricularImportCapabilities;
use App\Modules\Academic\Application\Queries\CurricularImportsViewData;
use App\Modules\Academic\Infrastructure\Jobs\PrepareCurricularImportJob;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Configuration\Application\Actions\RequestAcademicSourceOcr;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class RetryCurricularImport
{
    public function execute(string $id, User $actor, Request $request): CurricularImport
    {
        $view = app(CurricularImportsViewData::class);
        $previous = $view->find($id, $request);
        $capabilities = app(CurricularImportCapabilities::class)->toArray();
        if (! $capabilities['extraction']) {
            throw ValidationException::withMessages(['import' => 'La extracción con IA no está disponible.']);
        }

        return DB::transaction(function () use ($previous, $actor, $request, $view, $capabilities): CurricularImport {
            Career::query()->whereKey($previous->carrera_id)->lockForUpdate()->firstOrFail();
            app(ProcessLocks::class)->assertCareerEditable($previous->carrera_id);
            $previous = CurricularImport::query()->lockForUpdate()->findOrFail($previous->id);
            abort_unless($previous->estado_importacion === 'fallida', 409, 'Solo se puede reintentar una lectura fallida.');
            $retryKey = hash('sha256', 'curricular-retry:'.$previous->id);
            $existing = CurricularImport::query()->where('carrera_id', $previous->carrera_id)->where('clave_idempotencia_importacion', $retryKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $documents = $previous->documentos_importacion;
            foreach ($documents as $index => $record) {
                $source = AcademicSource::query()->where('carrera_id', $previous->carrera_id)->where('activo', true)->whereKey($record['source_id'])->lockForUpdate()->firstOrFail();
                if (isset($record['hash']) && ! hash_equals($record['hash'], app(CanonicalHasher::class)->hash($source->documento ?? []))) {
                    abort(409, 'Una fuente cambió. Inicie otra importación con sus documentos actuales.');
                }
                if (($record['kind'] ?? null) === 'pdf' && $source->objeto_pdf_id !== ($record['original_object_id'] ?? null)) {
                    abort(409, 'El PDF cambió. Inicie otra importación con el documento actual.');
                }
                if ($source->estado_ingesta !== 'fallida') {
                    continue;
                }
                if (($record['kind'] ?? null) !== 'pdf' || ! $capabilities['pdf']) {
                    throw ValidationException::withMessages(['import' => 'Primero debe estar disponible la lectura del documento.']);
                }
                $object = StoredObject::query()->where('carrera_id', $previous->carrera_id)->whereKey($record['original_object_id'])->firstOrFail();
                $stream = Storage::disk($object->disco)->readStream($object->ruta_interna);
                $temporary = tmpfile();
                if (! is_resource($stream) || $temporary === false) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    if (is_resource($temporary)) {
                        fclose($temporary);
                    }
                    throw ValidationException::withMessages(['import' => 'No se pudo recuperar el PDF original.']);
                }
                try {
                    stream_copy_to_stream($stream, $temporary);
                    $path = stream_get_meta_data($temporary)['uri'] ?? null;
                    if (! is_string($path)) {
                        throw ValidationException::withMessages(['import' => 'No se pudo recuperar el PDF original.']);
                    }
                    $file = new UploadedFile($path, $object->nombre_logico, 'application/pdf', null, true);
                    $execution = app(RequestAcademicSourceOcr::class)->execute($source, $file, $actor, $request);
                    $documents[$index]['execution_id'] = $execution->id;
                    $documents[$index]['hash'] = null;
                } finally {
                    fclose($stream);
                    fclose($temporary);
                }
            }
            $import = CurricularImport::query()->create([
                'carrera_id' => $previous->carrera_id, 'solicitado_por' => $actor->id, 'estado_importacion' => 'pendiente',
                'clave_idempotencia_importacion' => $retryKey, 'documentos_importacion' => $documents,
                'instrucciones_importacion' => $previous->instrucciones_importacion, 'solicitado_en' => now(),
            ]);
            JobExecution::query()->create([
                'tipo' => 'curriculo.extraer', 'cola' => 'integraciones', 'estado' => 'pendiente', 'clave_idempotencia' => 'curriculo.extraer:'.$import->id,
                'tipo_recurso' => 'importacion_curricular', 'recurso_id' => $import->id,
                'entrada' => ['import_id' => $import->id, 'retry_of' => $previous->id], 'intentos' => 0, 'intentos_maximos' => 3, 'progreso' => 0,
            ]);
            app(RecordAuditEvent::class)->execute(actorId: $actor->id, roleAssignmentId: $view->role($request)->id,
                action: 'curriculo.importacion_reintentada', resourceType: 'importacion_curricular', resourceId: $import->id,
                result: 'exito', metadata: ['retry_of' => $previous->id]);
            PrepareCurricularImportJob::dispatch($import->id)->afterCommit();

            return $import;
        });
    }
}
