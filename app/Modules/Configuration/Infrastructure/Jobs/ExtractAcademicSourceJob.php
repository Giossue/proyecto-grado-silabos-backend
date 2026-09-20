<?php

namespace App\Modules\Configuration\Infrastructure\Jobs;

use App\Modules\AiAssistance\Application\IndexAcademicSource;
use App\Modules\Configuration\Application\AcademicSourceOcrAssetStore;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrUnavailable;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSourceExtraction;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExtractAcademicSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $extractionId)
    {
        $this->onQueue('integraciones');
        $this->timeout = (int) config('mistral.timeout_seconds') + 30;
    }

    public function handle(
        AcademicSourceOcrGateway $gateway,
        IndexAcademicSource $indexer,
        AcademicSourceOcrAssetStore $assetStore,
        RecordAuditEvent $audit,
    ): void {
        if (! $this->markRunning()) {
            return;
        }

        try {
            $extraction = AcademicSourceExtraction::query()
                ->with(['source', 'pdfObject', 'execution'])
                ->findOrFail($this->extractionId);
            $result = $gateway->extract($extraction->pdfObject);
            $this->complete($extraction, $result, $indexer, $assetStore, $audit);
        } catch (AcademicSourceOcrContractException $exception) {
            $this->finishFailed('contrato_fuente_ocr_invalido', 'La respuesta de OCR no cumplió el contrato.', $audit);
        } catch (AcademicSourceOcrUnavailable $exception) {
            $this->markRetryable();
            throw $exception;
        } catch (Throwable $exception) {
            $this->markRetryable();
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->finishFailed(
            $exception instanceof AcademicSourceOcrUnavailable
                ? 'servicio_ocr_no_disponible'
                : 'extraccion_fuente_fallida',
            'No fue posible procesar el PDF. Puede solicitar un nuevo intento.',
            app(RecordAuditEvent::class),
        );
    }

    private function markRunning(): bool
    {
        return DB::transaction(function (): bool {
            $extraction = AcademicSourceExtraction::query()->lockForUpdate()->find($this->extractionId);
            if ($extraction === null || in_array($extraction->estado, ['completada', 'fallida'], true)) {
                return false;
            }
            if ($extraction->estado === 'en_ejecucion') {
                return false;
            }
            $execution = JobExecution::query()->lockForUpdate()->findOrFail($extraction->ejecucion_trabajo_id);
            $extraction->update([
                'estado' => 'en_ejecucion',
                'iniciada_en' => $extraction->iniciada_en ?? now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
            AcademicSource::query()->whereKey($extraction->fuente_academica_id)->update([
                'estado_ingesta' => 'en_ejecucion',
                'ingesta_actualizada_en' => now(),
            ]);
            $execution->update([
                'estado' => 'en_ejecucion',
                'intentos' => $execution->intentos + 1,
                'progreso' => 10,
                'iniciado_en' => $execution->iniciado_en ?? now(),
                'finalizado_en' => null,
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);

            return true;
        });
    }

    private function complete(
        AcademicSourceExtraction $extraction,
        AcademicSourceOcrResult $result,
        IndexAcademicSource $indexer,
        AcademicSourceOcrAssetStore $assetStore,
        RecordAuditEvent $audit,
    ): void {
        DB::transaction(function () use ($assetStore, $audit, $extraction, $indexer, $result): void {
            $locked = AcademicSourceExtraction::query()->lockForUpdate()->findOrFail($extraction->id);
            if ($locked->estado === 'completada') {
                return;
            }
            $source = AcademicSource::query()->lockForUpdate()->findOrFail($locked->fuente_academica_id);
            $execution = JobExecution::query()->lockForUpdate()->findOrFail($locked->ejecucion_trabajo_id);
            $materialized = $assetStore->materialize($source, $locked, $result);
            $content = $materialized['markdown'];
            if (trim($content) === '') {
                throw new AcademicSourceOcrContractException('OCR no devolvió contenido Markdown.');
            }
            $source->update([
                'contenido' => $content,
                'objeto_pdf_id' => $locked->objeto_pdf_id,
                'estado_ingesta' => 'completada',
                'ingesta_actualizada_en' => now(),
            ]);
            $fragmentCount = $indexer->execute($source);
            $locked->update([
                'estado' => 'completada',
                'paginas' => $result->pagesForStorage(),
                'metadatos' => [
                    'model' => $result->model,
                    'usage_info' => $result->usage,
                    'request_id' => $result->requestId,
                ],
                'huella_contenido' => hash('sha256', $content),
                'completada_en' => now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
            $execution->update([
                'estado' => 'completada',
                'progreso' => 100,
                'resultado' => [
                    'source_id' => $source->id,
                    'extraction_id' => $locked->id,
                    'model' => $result->model,
                    'page_count' => count($result->pages),
                    'fragment_count' => $fragmentCount,
                    'image_count' => $materialized['image_count'],
                ],
                'finalizado_en' => now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
            $audit->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'fuente.extraccion_completada',
                resourceType: 'fuente_academica',
                resourceId: $source->id,
                result: 'exito',
                metadata: [
                    'model' => $result->model,
                    'page_count' => count($result->pages),
                    'fragment_count' => $fragmentCount,
                    'image_count' => $materialized['image_count'],
                ],
                correlationId: $execution->correlacion_id,
            );
        });
    }

    private function markRetryable(): void
    {
        DB::transaction(function (): void {
            $extraction = AcademicSourceExtraction::query()->lockForUpdate()->find($this->extractionId);
            if ($extraction === null || $extraction->estado === 'completada') {
                return;
            }
            $extraction->update(['estado' => 'pendiente']);
            AcademicSource::query()->whereKey($extraction->fuente_academica_id)->update([
                'estado_ingesta' => 'pendiente',
                'ingesta_actualizada_en' => now(),
            ]);
            JobExecution::query()->whereKey($extraction->ejecucion_trabajo_id)->update([
                'estado' => 'pendiente',
                'progreso' => 0,
                'finalizado_en' => null,
            ]);
        });
    }

    private function finishFailed(string $code, string $message, RecordAuditEvent $audit): void
    {
        DB::transaction(function () use ($audit, $code, $message): void {
            $extraction = AcademicSourceExtraction::query()->lockForUpdate()->find($this->extractionId);
            if ($extraction === null || $extraction->estado === 'completada') {
                return;
            }
            $extraction->update([
                'estado' => 'fallida',
                'codigo_error' => $code,
                'mensaje_error' => $message,
                'fallida_en' => now(),
            ]);
            AcademicSource::query()->whereKey($extraction->fuente_academica_id)->update([
                'estado_ingesta' => 'fallida',
                'ingesta_actualizada_en' => now(),
            ]);
            $execution = JobExecution::query()->lockForUpdate()->find($extraction->ejecucion_trabajo_id);
            if ($execution !== null) {
                $execution->update([
                    'estado' => 'fallida',
                    'progreso' => 0,
                    'resultado' => null,
                    'codigo_error' => $code,
                    'mensaje_error' => $message,
                    'finalizado_en' => now(),
                ]);
                $audit->execute(
                    actorId: null,
                    roleAssignmentId: null,
                    action: 'fuente.extraccion_fallida',
                    resourceType: 'fuente_academica',
                    resourceId: $extraction->fuente_academica_id,
                    result: 'fallido',
                    metadata: ['error_code' => $code],
                    correlationId: $execution->correlacion_id,
                );
            }
        });
    }
}
