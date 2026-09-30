<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\CurricularImportCapabilities;
use App\Modules\Academic\Application\CurricularWorkbookReader;
use App\Modules\Academic\Application\Queries\CurricularImportsViewData;
use App\Modules\Academic\Infrastructure\Jobs\PrepareCurricularImportJob;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Configuration\Application\AcademicSourceDocument;
use App\Modules\Configuration\Application\Actions\CreateAcademicSource;
use App\Modules\Configuration\Application\Actions\RequestAcademicSourceOcr;
use App\Modules\Configuration\Application\ProjectDataUsage;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class RequestCurricularImport
{
    public function __construct(
        private readonly CurricularImportsViewData $view,
        private readonly ProcessLocks $locks,
        private readonly CanonicalHasher $hasher,
        private readonly CurricularImportCapabilities $capabilities,
        private readonly CurricularWorkbookReader $workbooks,
        private readonly AcademicSourceDocument $documents,
        private readonly CreateAcademicSource $createSource,
        private readonly RequestAcademicSourceOcr $ocr,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param  list<UploadedFile>  $files */
    public function execute(array $files, ?string $instruction, User $actor, Request $request): CurricularImport
    {
        $role = $this->view->role($request);
        $careerId = (string) $role->carrera_id;
        $this->locks->assertCareerEditable($careerId);
        $capabilities = $this->capabilities->toArray();
        if (! $capabilities['extraction']) {
            throw ValidationException::withMessages(['files' => 'La extracción con IA no está configurada. Solicite a Administración habilitar el servicio.']);
        }
        if (ProjectDataUsage::used() === []) {
            throw ValidationException::withMessages(['files' => 'La plantilla del sílabo todavía no tiene datos del Proyecto de carrera. Administración debe agregarlos primero.']);
        }
        $fileHashes = [];
        foreach ($files as $file) {
            $kind = strtolower($file->getClientOriginalExtension());
            if (! in_array($kind, ['pdf', 'xlsx'], true) || ! $capabilities[$kind]) {
                throw ValidationException::withMessages(['files' => 'El formato del archivo no está disponible para importación.']);
            }
            $hash = hash_file('sha256', (string) $file->getRealPath());
            if ($hash === false) {
                throw ValidationException::withMessages(['files' => 'No se pudo comprobar uno de los archivos.']);
            }
            $fileHashes[] = $hash;
        }
        sort($fileHashes);
        $key = $this->hasher->hash(['version' => 'curricular-v1', 'career' => $careerId, 'files' => $fileHashes, 'sources' => [], 'instruction' => trim($instruction ?? '')]);

        return DB::transaction(function () use ($actor, $careerId, $files, $instruction, $key, $request, $role): CurricularImport {
            Career::query()->whereKey($careerId)->lockForUpdate()->firstOrFail();
            $this->locks->assertCareerEditable($careerId);
            $existing = CurricularImport::query()->where('carrera_id', $careerId)->where('clave_idempotencia_importacion', $key)->first();
            if ($existing !== null && $existing->estado_importacion !== 'aplicada') {
                return $existing;
            }
            if ($existing !== null) {
                $key = $this->hasher->hash(['previous' => $key, 'attempt' => (string) Str::uuid()]);
            }
            $documentRecords = [];
            foreach ($files as $file) {
                $kind = strtolower($file->getClientOriginalExtension());
                $baseName = Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 180, '') ?: 'Documento curricular';
                $name = $baseName;
                $suffix = 2;
                while (AcademicSource::query()->where('carrera_id', $careerId)->where('nombre', $name)->exists()) {
                    $name = $baseName.' ('.$suffix++.')';
                }
                $source = $this->createSource->execute(['nombre' => $name], $actor, $request);
                if ($kind === 'pdf') {
                    $execution = $this->ocr->execute($source, $file, $actor, $request);
                    $documentRecords[] = ['source_id' => $source->id, 'name' => $name, 'hash' => null, 'kind' => 'pdf', 'execution_id' => $execution->id, 'original_object_id' => $execution->entrada['pdf_object_id'] ?? null];
                } else {
                    try {
                        $document = $this->documents->normalize($this->workbooks->read((string) $file->getRealPath()));
                    } catch (RuntimeException $exception) {
                        throw ValidationException::withMessages(['files' => $exception->getMessage()]);
                    }
                    $source->update(['documento' => $document, 'estado_ingesta' => 'completada', 'ingesta_actualizada_en' => now()]);
                    $hash = hash_file('sha256', (string) $file->getRealPath());
                    $path = 'curricular-imports/'.$careerId.'/'.$hash.'.xlsx';
                    if (! Storage::disk('private')->exists($path)) {
                        $stream = fopen((string) $file->getRealPath(), 'rb');
                        if ($stream === false) {
                            throw ValidationException::withMessages(['files' => 'No se pudo conservar el libro original.']);
                        }
                        try {
                            if (! Storage::disk('private')->put($path, $stream)) {
                                throw ValidationException::withMessages(['files' => 'No se pudo conservar el libro original.']);
                            }
                        } finally {
                            fclose($stream);
                        }
                    }
                    $object = StoredObject::query()->firstOrCreate(['disco' => 'private', 'ruta_interna' => $path], [
                        'nombre_logico' => Str::limit($file->getClientOriginalName(), 255, ''), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'tamano_bytes' => $file->getSize(), 'huella_sha256' => $hash, 'clasificacion' => 'curricular_workbook',
                        'estado' => 'activo', 'propietario_usuario_id' => $actor->id, 'carrera_id' => $careerId, 'almacenado_en' => now(),
                    ]);
                    $documentRecords[] = ['source_id' => $source->id, 'name' => $name, 'hash' => $this->hasher->hash($document), 'kind' => 'xlsx', 'execution_id' => null, 'file_hash' => $hash, 'original_object_id' => $object->id];
                }
            }
            $import = CurricularImport::query()->create([
                'carrera_id' => $careerId, 'solicitado_por' => $actor->id, 'estado_importacion' => 'pendiente',
                'clave_idempotencia_importacion' => $key, 'documentos_importacion' => $documentRecords,
                'instrucciones_importacion' => $instruction, 'solicitado_en' => now(),
            ]);
            JobExecution::query()->create([
                'tipo' => 'curriculo.extraer', 'cola' => 'integraciones', 'estado' => 'pendiente', 'clave_idempotencia' => 'curriculo.extraer:'.$import->id,
                'tipo_recurso' => 'importacion_curricular', 'recurso_id' => $import->id,
                'entrada' => ['import_id' => $import->id], 'intentos' => 0, 'intentos_maximos' => 3, 'progreso' => 0,
            ]);
            $this->audit->execute(actorId: $actor->id, roleAssignmentId: $role->id, action: 'curriculo.importacion_solicitada', resourceType: 'importacion_curricular', resourceId: $import->id, result: 'exito', metadata: ['document_count' => count($documentRecords)]);
            PrepareCurricularImportJob::dispatch($import->id)->afterCommit();

            return $import;
        });
    }
}
