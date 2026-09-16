<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiExecution;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\SyllabusAiReview;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use Illuminate\Support\Facades\DB;

class RefreshSyllabusAiReview
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function forExecution(string $executionId): void
    {
        $reviewId = AiExecution::query()->whereKey($executionId)->value('revision_ia_silabo_id');
        if (is_string($reviewId)) {
            $this->execute($reviewId);
        }
    }

    public function execute(string $reviewId): SyllabusAiReview
    {
        return DB::transaction(function () use ($reviewId): SyllabusAiReview {
            $review = SyllabusAiReview::query()->lockForUpdate()->findOrFail($reviewId);
            if (in_array($review->estado_revision_ia, ['completada', 'no_concluyente', 'parcial', 'fallida'], true)) {
                return $review;
            }

            $counts = AiExecution::query()
                ->where('revision_ia_silabo_id', $review->id)
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw("COUNT(*) FILTER (WHERE estado_ejecucion_ia IN ('completada', 'no_concluyente', 'fallida')) AS finalizados")
                ->selectRaw("COUNT(*) FILTER (WHERE estado_ejecucion_ia = 'fallida') AS fallidos")
                ->selectRaw("COUNT(*) FILTER (WHERE estado_ejecucion_ia = 'no_concluyente') AS no_concluyentes")
                ->selectRaw("COUNT(*) FILTER (WHERE estado_ejecucion_ia = 'en_ejecucion') AS en_ejecucion")
                ->firstOrFail();
            $total = (int) $counts->getAttribute('total');
            $finished = (int) $counts->getAttribute('finalizados');
            $failed = (int) $counts->getAttribute('fallidos');
            $inconclusive = (int) $counts->getAttribute('no_concluyentes');
            $running = (int) $counts->getAttribute('en_ejecucion');
            $withRecommendations = AiExecution::query()
                ->where('revision_ia_silabo_id', $review->id)
                ->whereHas('recommendations')
                ->count();
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

            $review->update([
                'estado_revision_ia' => $state,
                'campos_finalizados' => $finished,
                'campos_con_recomendaciones' => $withRecommendations,
                'campos_fallidos' => $failed,
                'iniciado_en' => $state === 'pendiente' ? null : ($review->iniciado_en ?? now()),
                'completado_en' => $terminal ? now() : null,
            ]);

            if ($terminal) {
                InternalNotification::query()->firstOrCreate(
                    [
                        'usuario_id' => $review->solicitado_por,
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
