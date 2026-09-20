<?php

namespace App\Modules\Configuration\Application;

use App\Modules\AiAssistance\Application\IndexAcademicSource;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Support\Facades\DB;

final class AcademicSourceOcrExecutionFinalizer
{
    public function __construct(
        private readonly AcademicSourceOcrAssetStore $assets,
        private readonly AcademicSourceDocument $documents,
        private readonly IndexAcademicSource $indexer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function complete(JobExecution $execution, AcademicSourceOcrResult $result): void
    {
        $source = AcademicSource::query()->findOrFail($execution->recurso_id);
        $materialized = $this->assets->materialize($source, $execution, $result);
        $document = $this->documents->normalize($materialized['document']);
        $text = $this->documents->text($document);
        if (trim($text) === '') {
            throw new AcademicSourceOcrContractException('OpenParser no devolvió contenido utilizable.');
        }

        DB::transaction(function () use ($document, $execution, $materialized, $result, $text): void {
            $lockedExecution = JobExecution::query()->lockForUpdate()->findOrFail($execution->id);
            if ($lockedExecution->estado === 'completada') {
                return;
            }
            $source = AcademicSource::query()->lockForUpdate()->findOrFail($lockedExecution->recurso_id);
            $source->update([
                'documento' => $document,
                'version_documento' => 1,
                'contenido' => $text,
                'objeto_pdf_id' => $lockedExecution->entrada['pdf_object_id'] ?? $source->objeto_pdf_id,
                'estado_ingesta' => 'completada',
                'ingesta_actualizada_en' => now(),
            ]);
            $fragmentCount = $this->indexer->execute($source);
            $lockedExecution->update([
                'estado' => 'completada',
                'progreso' => 100,
                'resultado' => [
                    'source_id' => $source->id,
                    'provider_job_id' => $lockedExecution->resultado['provider_job_id'] ?? null,
                    'model' => $result->model,
                    'normalization' => $result->usage['normalization'] ?? null,
                    'page_count' => count($result->pages),
                    'fragment_count' => $fragmentCount,
                    'image_count' => $materialized['image_count'],
                    'document_fingerprint' => hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ],
                'finalizado_en' => now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
            $this->audit->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'fuente.extraccion_completada',
                resourceType: 'fuente_academica',
                resourceId: $source->id,
                result: 'exito',
                metadata: [
                    'model' => $result->model,
                    'normalization' => $result->usage['normalization'] ?? null,
                    'page_count' => count($result->pages),
                    'fragment_count' => $fragmentCount,
                    'image_count' => $materialized['image_count'],
                ],
                correlationId: $lockedExecution->correlacion_id,
            );
        });
    }

    public function fail(string $executionId, string $code, string $message): void
    {
        DB::transaction(function () use ($code, $executionId, $message): void {
            $execution = JobExecution::query()->lockForUpdate()->find($executionId);
            if ($execution === null || $execution->estado === 'completada') {
                return;
            }
            $execution->update([
                'estado' => 'fallida',
                'progreso' => 0,
                'resultado' => null,
                'codigo_error' => $code,
                'mensaje_error' => $message,
                'finalizado_en' => now(),
            ]);
            AcademicSource::query()->whereKey($execution->recurso_id)->update([
                'estado_ingesta' => 'fallida',
                'ingesta_actualizada_en' => now(),
            ]);
            $this->audit->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'fuente.extraccion_fallida',
                resourceType: 'fuente_academica',
                resourceId: $execution->recurso_id,
                result: 'fallido',
                metadata: ['error_code' => $code],
                correlationId: $execution->correlacion_id,
            );
        });
    }
}
