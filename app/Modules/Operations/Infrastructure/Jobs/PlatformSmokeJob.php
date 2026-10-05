<?php

namespace App\Modules\Operations\Infrastructure\Jobs;

use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PlatformSmokeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(public readonly string $executionId)
    {
        $this->onQueue('critica');
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $execution = JobExecution::query()->lockForUpdate()->find($this->executionId);

            if ($execution === null || $execution->estado === 'completada') {
                return;
            }

            $execution->update([
                'estado' => 'completada',
                'intentos' => max(1, $this->attempts()),
                'resultado' => ['message' => 'platform-smoke-ok'],
                'iniciado_en' => $execution->iniciado_en ?? now(),
                'finalizado_en' => now(),
                'mensaje_error' => null,
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
        JobExecution::query()->whereKey($this->executionId)->update([
            'estado' => 'fallida',
            'intentos' => max(1, $this->attempts()),
            'mensaje_error' => 'La verificación de la plataforma falló.',
            'finalizado_en' => now(),
        ]);
    }
}
