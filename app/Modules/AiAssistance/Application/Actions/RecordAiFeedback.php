<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La decisión de la persona sobre una recomendación es un mensaje `decision` agregado a
 * la sesión del campo; el análisis terminal no se modifica.
 */
class RecordAiFeedback
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Localiza, bloqueando, el análisis del campo que contiene la recomendación.
     */
    public static function lockedAnalysisFor(Syllabus $syllabus, FieldDefinition $field, string $recommendationId): ?AiHistoryEntry
    {
        return AiHistoryEntry::query()
            ->session(AiHistoryEntry::fieldSession($syllabus->id, $field->id))
            ->ofType('analisis')
            ->whereRaw("mensaje_ia->'recomendaciones' @> CAST(? AS jsonb)", [json_encode([['id' => $recommendationId]])])
            ->lockForUpdate()
            ->first();
    }

    public function execute(
        Syllabus $syllabus,
        FieldDefinition $field,
        string $recommendationId,
        string $decision,
        User $actor,
        Request $request,
    ): AiHistoryEntry {
        if (! in_array($decision, ['aceptada', 'ignorada', 'no_util'], true)) {
            throw ValidationException::withMessages(['decision' => 'Seleccione una decisión válida.']);
        }

        return DB::transaction(function () use ($actor, $decision, $field, $recommendationId, $request, $syllabus): AiHistoryEntry {
            $analysis = self::lockedAnalysisFor($syllabus, $field, $recommendationId);
            if ($analysis === null || $analysis->state() !== 'completada') {
                throw ValidationException::withMessages([
                    'recommendation' => 'La recomendación ya no está disponible para esta decisión.',
                ]);
            }
            $existing = AiHistoryEntry::query()
                ->session($analysis->sesion_id)
                ->ofType('decision')
                ->where('mensaje_ia->entrada->recomendacion_id', $recommendationId)
                ->where('mensaje_ia->entrada->usuario_id', $actor->id)
                ->where('mensaje_ia->entrada->decision', $decision)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            $activeRole = $this->roles->resolve($request);
            $feedback = AiHistoryEntry::query()->create([
                'sesion_id' => $analysis->sesion_id,
                'mensaje_ia' => [
                    'tipo' => 'decision',
                    'estado' => 'registrada',
                    'entrada' => [
                        'analisis_id' => $analysis->id,
                        'recomendacion_id' => $recommendationId,
                        'usuario_id' => $actor->id,
                        'asignacion_rol_id' => $activeRole?->id,
                        'decision' => $decision,
                    ],
                ],
            ]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: "ia.recomendacion_{$decision}",
                resourceType: 'recomendacion_ia',
                resourceId: $recommendationId,
                result: 'exito',
                metadata: ['ai_execution_id' => $analysis->id],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $feedback;
        });
    }
}
