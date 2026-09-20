<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Infrastructure\Jobs\SubmitAcademicSourceOcrJob;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestAcademicSourceOcr
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly AcademicSourceOcrGateway $gateway,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
    ) {}

    public function execute(AcademicSource $source, UploadedFile $file, User $actor, Request $request): JobExecution
    {
        $activeRole = $this->roles->resolve($request);
        abort_unless(
            $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
                && $activeRole->carrera_id === $source->carrera_id,
            403,
        );
        $this->locks->assertCareerEditable($source->carrera_id);
        $pathInfo = $this->storePdf($source, $file);
        $model = (string) config('openparser.ocr_model');
        $adapterVersion = $this->gateway->version();
        $dispatch = false;

        $execution = DB::transaction(function () use (
            $activeRole, $actor, $adapterVersion, $model, $pathInfo, $request, $source, &$dispatch,
        ): JobExecution {
            $lockedSource = AcademicSource::query()->lockForUpdate()->findOrFail($source->id);
            $object = StoredObject::query()->firstOrCreate(
                ['disco' => 'private', 'ruta_interna' => $pathInfo['path']],
                [
                    'nombre_logico' => $pathInfo['name'],
                    'mime' => 'application/pdf',
                    'tamano_bytes' => $pathInfo['size'],
                    'huella_sha256' => $pathInfo['hash'],
                    'clasificacion' => 'academic_source_pdf',
                    'estado' => 'activo',
                    'propietario_usuario_id' => $actor->id,
                    'carrera_id' => $lockedSource->carrera_id,
                    'almacenado_en' => now(),
                ],
            );
            if ($object->mime !== 'application/pdf' || $object->tamano_bytes !== $pathInfo['size'] || ! hash_equals($object->huella_sha256, $pathInfo['hash'])) {
                throw ValidationException::withMessages(['pdf' => 'El archivo privado existente no coincide con el PDF recibido.']);
            }

            $requestFingerprint = hash('sha256', "{$pathInfo['hash']}:{$model}:{$adapterVersion}");
            $key = "fuente.openparser:{$lockedSource->id}:{$requestFingerprint}";
            $correlationId = $request->attributes->getString('correlation_id');
            $execution = JobExecution::query()->where('clave_idempotencia', $key)->lockForUpdate()->first();
            if ($execution === null) {
                $execution = JobExecution::query()->create([
                    'tipo' => 'fuente_academica.openparser',
                    'cola' => 'integraciones',
                    'estado' => 'pendiente',
                    'clave_idempotencia' => $key,
                    'correlacion_id' => Str::isUuid($correlationId) ? $correlationId : (string) Str::uuid(),
                    'tipo_recurso' => 'fuente_academica',
                    'recurso_id' => $lockedSource->id,
                    'entrada' => [
                        'source_id' => $lockedSource->id,
                        'pdf_object_id' => $object->id,
                        'pdf_hash' => $pathInfo['hash'],
                        'model' => $model,
                        'adapter_version' => $adapterVersion,
                        'requested_by' => $actor->id,
                    ],
                    'intentos' => 0,
                    'intentos_maximos' => 3,
                    'progreso' => 0,
                ]);
                $dispatch = true;
            } elseif ($execution->estado === 'fallida') {
                $execution->update([
                    'estado' => 'pendiente',
                    'progreso' => 0,
                    'resultado' => null,
                    'codigo_error' => null,
                    'mensaje_error' => null,
                    'iniciado_en' => null,
                    'finalizado_en' => null,
                ]);
                $dispatch = true;
            }

            $lockedSource->update([
                'objeto_pdf_id' => $object->id,
                'estado_ingesta' => $execution->estado === 'completada' ? 'completada' : 'pendiente',
                'ingesta_actualizada_en' => now(),
            ]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'fuente.extraccion_solicitada',
                resourceType: 'fuente_academica',
                resourceId: $lockedSource->id,
                result: 'exito',
                metadata: ['pdf_hash' => $pathInfo['hash'], 'pdf_size' => $pathInfo['size'], 'model' => $model],
                correlationId: $execution->correlacion_id,
            );

            return $execution;
        });

        if ($dispatch) {
            SubmitAcademicSourceOcrJob::dispatch($execution->id)->afterCommit();
        }

        return $execution;
    }

    /** @return array{path: string, name: string, size: int, hash: string} */
    private function storePdf(AcademicSource $source, UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! is_file($realPath)) {
            throw ValidationException::withMessages(['pdf' => 'No se pudo leer el PDF recibido.']);
        }
        $size = filesize($realPath);
        $hash = hash_file('sha256', $realPath);
        if (! is_int($size) || ! is_string($hash)) {
            throw ValidationException::withMessages(['pdf' => 'No se pudo calcular la huella del PDF.']);
        }
        if ($size > (int) config('openparser.max_upload_bytes')) {
            throw ValidationException::withMessages(['pdf' => 'El PDF supera el tamaño máximo permitido.']);
        }
        $path = "academic-sources/{$source->id}/{$hash}.pdf";
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            $stream = fopen($realPath, 'rb');
            if ($stream === false || ! $disk->put($path, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw ValidationException::withMessages(['pdf' => 'No se pudo guardar el PDF privado.']);
            }
            fclose($stream);
        }

        return [
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName() ?: 'fuente-academica.pdf', 255, ''),
            'size' => $size,
            'hash' => $hash,
        ];
    }
}
