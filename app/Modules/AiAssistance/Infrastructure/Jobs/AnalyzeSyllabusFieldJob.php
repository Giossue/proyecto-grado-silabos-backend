<?php

namespace App\Modules\AiAssistance\Infrastructure\Jobs;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalyzeSyllabusFieldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $executionId)
    {
        $this->onQueue('ia');
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $analysis = AiHistoryEntry::query()->lockForUpdate()->find($this->executionId);
            if ($analysis === null || $analysis->type() !== 'analisis' || ! $analysis->isOpen()) {
                return;
            }
            $terminal = [
                'estado' => 'fallida', 'codigo_error' => 'revision_ia_retirada',
                'mensaje_error' => 'La revisión automática del sílabo fue retirada. La ayuda disponible permite preparar contenido de unidades.',
                'completado_en' => now()->toIso8601String(),
            ];
            $analysis->amend($terminal);
            JobExecution::query()->whereKey($analysis->inputString('ejecucion_trabajo_id'))
                ->whereIn('estado', ['pendiente', 'en_ejecucion'])->update([
                    'estado' => 'fallida', 'codigo_error' => $terminal['codigo_error'],
                    'mensaje_error' => $terminal['mensaje_error'], 'finalizado_en' => now(),
                ]);
            $review = AiHistoryEntry::query()->lockForUpdate()->find($analysis->inputString('revision_id'));
            if ($review !== null && $review->type() === 'revision' && $review->isOpen()) {
                $review->amend($terminal);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        $this->handle();
    }
}
