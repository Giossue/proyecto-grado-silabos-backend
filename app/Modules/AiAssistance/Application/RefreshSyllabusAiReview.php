<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula el progreso de una revisión integral (mensaje `revision`) a partir de los
 * análisis de campo que la referencian en `entrada.revision_id`.
 */
class RefreshSyllabusAiReview
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function forExecution(string $executionId): void
    {
        $reviewId = AiHistoryEntry::query()->find($executionId)?->input('revision_id');
        if (is_string($reviewId)) {
            $this->execute($reviewId);
        }
    }

    public function execute(string $reviewId): AiHistoryEntry
    {
        return DB::transaction(function () use ($reviewId): AiHistoryEntry {
            $review = AiHistoryEntry::query()->lockForUpdate()->findOrFail($reviewId);
            if (! $review->isOpen()) {
                return $review;
            }

            $states = AiHistoryEntry::query()
                ->fieldAnalysesOf((string) $review->input('silabo_id'))
                ->where('mensaje_ia->entrada->revision_id', $review->id)
                ->get()
                ->map(fn (AiHistoryEntry $analysis): array => [
                    'state' => $analysis->state(),
                    'recommendations' => $analysis->recommendations() !== [],
                ]);
            $total = $states->count();
            $finished = $states->whereIn('state', ['completada', 'no_concluyente', 'fallida'])->count();
            $failed = $states->where('state', 'fallida')->count();
            $inconclusive = $states->where('state', 'no_concluyente')->count();
            $running = $states->where('state', 'en_ejecucion')->count();
            $withRecommendations = $states->where('recommendations', true)->count();
            $terminal = $total > 0 && $finished === $total;
            $state = 'pendiente';
            if ($terminal) {
                $state = match (true) {
                    $failed === $total => 'fallida',
                    $inconclusive === $total => 'no_concluyente',
                    $failed > 0 => 'parcial',
                    default => 'completada',
                };
            } elseif ($running > 0 || $finished > 0) {
                $state = 'en_ejecucion';
            }

            $review->amend([
                'estado' => $state,
                'campos_finalizados' => $finished,
                'campos_con_recomendaciones' => $withRecommendations,
                'campos_fallidos' => $failed,
                'iniciado_en' => $state === 'pendiente' ? null : ($review->part('iniciado_en') ?? now()->toIso8601String()),
                'completado_en' => $terminal ? now()->toIso8601String() : null,
            ]);

            if ($terminal) {
                InternalNotification::query()->firstOrCreate(
                    [
                        'usuario_id' => $review->input('solicitado_por'),
                        'clave_deduplicacion' => "ia.revision_silabo.finalizada:{$review->id}",
                    ],
                    [
                        'tipo' => 'ia.revision_silabo.finalizada',
                        'titulo' => 'Revisión de IA finalizada',
                        'mensaje' => $state === 'fallida'
                            ? 'La ayuda no estuvo disponible. Puede continuar sin ella o volver a intentarlo tras editar.'
                            : 'La revisión integral terminó. Las sugerencias son opcionales y ningún cambio se aplicó automáticamente.',
                        'tipo_recurso' => 'revision_ia_silabo',
                        'recurso_id' => $review->id,
                        'notificado_en' => now(),
                    ],
                );
                $this->audit->execute(
                    actorId: null,
                    roleAssignmentId: null,
                    action: 'ia.revision_silabo_finalizada',
                    resourceType: 'revision_ia_silabo',
                    resourceId: $review->id,
                    result: $state === 'fallida' ? 'fallido' : 'exito',
                    metadata: [
                        'status' => $state,
                        'field_count' => $total,
                        'failed_fields' => $failed,
                        'fields_with_recommendations' => $withRecommendations,
                    ],
                );
            }

            return $review->refresh();
        });
    }
}
