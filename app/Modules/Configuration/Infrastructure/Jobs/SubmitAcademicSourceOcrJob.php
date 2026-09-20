<?php

namespace App\Modules\Configuration\Infrastructure\Jobs;

use App\Modules\Configuration\Application\AcademicSourceOcrExecutionFinalizer;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SubmitAcademicSourceOcrJob implements ShouldQueue
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
        $existingJob = $execution->resultado['provider_job_id'] ?? null;
        if (is_string($existingJob) && $existingJob !== '') {
            PollAcademicSourceOcrJob::dispatch($execution->id)->delay(now()->addSeconds((int) config('openparser.poll_delay_seconds')));

            return;
        }

        DB::transaction(function () use ($execution): void {
            $locked = JobExecution::query()->lockForUpdate()->findOrFail($execution->id);
            $locked->update([
                'estado' => 'en_ejecucion',
                'intentos' => $locked->intentos + 1,
                'progreso' => 10,
                'iniciado_en' => $locked->iniciado_en ?? now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
            AcademicSource::query()->whereKey($locked->recurso_id)->update([
                'estado_ingesta' => 'en_ejecucion',
                'ingesta_actualizada_en' => now(),
            ]);
        });

        try {
            $pdfObjectId = $execution->entrada['pdf_object_id'] ?? null;
            if (! is_string($pdfObjectId)) {
                throw new AcademicSourceOcrContractException('La ejecución no referencia un PDF privado.');
            }
            $pdf = StoredObject::query()->whereKey($pdfObjectId)->firstOrFail();
            $providerJobId = $gateway->submit($pdf);
        } catch (AcademicSourceOcrContractException $exception) {
            $finalizer->fail($execution->id, 'contrato_openparser_invalido', 'OpenParser rechazó el documento o devolvió una respuesta inválida.');

            return;
        }

        DB::transaction(function () use ($execution, $providerJobId): void {
            $locked = JobExecution::query()->lockForUpdate()->findOrFail($execution->id);
            $locked->update([
                'resultado' => ['provider_job_id' => $providerJobId, 'submitted_at' => now()->toIso8601String()],
                'progreso' => 20,
            ]);
        });
        PollAcademicSourceOcrJob::dispatch($execution->id)->delay(now()->addSeconds((int) config('openparser.poll_delay_seconds')));
    }

    public function failed(Throwable $exception): void
    {
        app(AcademicSourceOcrExecutionFinalizer::class)->fail(
            $this->executionId,
            'openparser_no_disponible',
            'No fue posible enviar el PDF a OpenParser. Puede reintentarlo.',
        );
    }
}
