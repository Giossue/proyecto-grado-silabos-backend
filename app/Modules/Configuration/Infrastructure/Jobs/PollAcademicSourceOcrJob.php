<?php

namespace App\Modules\Configuration\Infrastructure\Jobs;

use App\Modules\Configuration\Application\AcademicSourceOcrExecutionFinalizer;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PollAcademicSourceOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    /** @var list<int> */
    public array $backoff = [10, 30, 120];

    public function __construct(public readonly string $executionId)
    {
        $this->onQueue('integraciones');
    }

    public function handle(AcademicSourceOcrGateway $gateway, AcademicSourceOcrExecutionFinalizer $finalizer): void
    {
        $execution = JobExecution::query()->find($this->executionId);
        if ($execution === null || in_array($execution->estado, ['completada', 'fallida'], true)) {
            return;
        }
        $providerJobId = $execution->resultado['provider_job_id'] ?? null;
        if (! is_string($providerJobId) || $providerJobId === '') {
            $finalizer->fail($execution->id, 'paddleocr_sin_trabajo', 'No se encontró el identificador del trabajo externo.');

            return;
        }
        if ($execution->encolado_en?->lt(now()->subMinutes((int) config('paddleocr.max_poll_minutes')))) {
            $finalizer->fail($execution->id, 'paddleocr_tiempo_agotado', 'PaddleOCR excedió el tiempo máximo de procesamiento.');

            return;
        }

        try {
            $status = $gateway->status($providerJobId);
            if ($status->state === 'failed') {
                $finalizer->fail($execution->id, 'paddleocr_trabajo_fallido', 'PaddleOCR no pudo procesar el PDF.');

                return;
            }
            if ($status->state !== 'done') {
                $execution->update(['estado' => 'en_ejecucion', 'progreso' => $status->progress]);
                self::dispatch($execution->id)->delay(now()->addSeconds((int) config('paddleocr.poll_delay_seconds')));

                return;
            }
            if ($status->result === null) {
                throw new AcademicSourceOcrContractException('El trabajo terminó sin URL de resultado.');
            }
            $finalizer->complete($execution, $gateway->result($providerJobId, $status->result));
        } catch (AcademicSourceOcrContractException $exception) {
            $finalizer->fail($execution->id, 'contrato_paddleocr_invalido', 'PaddleOCR devolvió un resultado que no se pudo interpretar.');
        }
    }

    public function failed(Throwable $exception): void
    {
        app(AcademicSourceOcrExecutionFinalizer::class)->fail(
            $this->executionId,
            'paddleocr_no_disponible',
            'No fue posible consultar el resultado de PaddleOCR. Puede reintentarlo.',
        );
    }
}
