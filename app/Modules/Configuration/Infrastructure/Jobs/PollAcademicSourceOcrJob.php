<?php

namespace App\Modules\Configuration\Infrastructure\Jobs;

use App\Modules\Configuration\Application\AcademicSourceOcrExecutionFinalizer;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceDocumentNormalizer;
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

    public function handle(
        AcademicSourceOcrGateway $gateway,
        AcademicSourceDocumentNormalizer $normalizer,
        AcademicSourceOcrExecutionFinalizer $finalizer,
    ): void {
        $execution = JobExecution::query()->find($this->executionId);
        if ($execution === null || in_array($execution->estado, ['completada', 'fallida'], true)) {
            return;
        }
        $providerJobId = $execution->resultado['provider_job_id'] ?? null;
        if (! is_string($providerJobId) || $providerJobId === '') {
            $finalizer->fail($execution->id, 'openparser_sin_trabajo', 'No se encontró el identificador del trabajo externo.');

            return;
        }
        if ($execution->encolado_en?->lt(now()->subMinutes((int) config('openparser.max_poll_minutes')))) {
            $finalizer->fail($execution->id, 'openparser_tiempo_agotado', 'OpenParser excedió el tiempo máximo de procesamiento.');

            return;
        }

        try {
            $status = $gateway->status($providerJobId);
            if ($status->state === 'failed') {
                $finalizer->fail($execution->id, 'openparser_trabajo_fallido', 'OpenParser no pudo procesar el PDF.');

                return;
            }
            if ($status->state !== 'done') {
                $execution->update(['estado' => 'en_ejecucion', 'progreso' => $status->progress]);
                self::dispatch($execution->id)->delay(now()->addSeconds((int) config('openparser.poll_delay_seconds')));

                return;
            }
            if ($status->result === null) {
                throw new AcademicSourceOcrContractException('El trabajo terminó sin URL de resultado.');
            }
            $execution->update(['estado' => 'en_ejecucion', 'progreso' => 96]);
            $ocrResult = $gateway->result($providerJobId, $status->result);
            $finalizer->complete($execution, $normalizer->normalize($ocrResult));
        } catch (AcademicSourceOcrContractException $exception) {
            $finalizer->fail($execution->id, 'contrato_ingesta_invalido', $exception->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        app(AcademicSourceOcrExecutionFinalizer::class)->fail(
            $this->executionId,
            'proveedor_ingesta_no_disponible',
            'No fue posible completar el procesamiento del PDF. Puede reintentarlo.',
        );
    }
}
