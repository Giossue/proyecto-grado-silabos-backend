<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\Actions\UpdateDraftField;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyAiRecommendation
{
    public function __construct(
        private readonly UpdateDraftField $updateDraftField,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly CanonicalHasher $hasher,
    ) {}

    /** @return array{syllabus: Syllabus, value: string} */
    public function execute(
        Syllabus $syllabus,
        FieldDefinition $field,
        string $recommendationId,
        int $expectedLockVersion,
        User $actor,
        Request $request,
    ): array {
        return DB::transaction(function () use ($actor, $expectedLockVersion, $field, $recommendationId, $request, $syllabus): array {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $analysis = RecordAiFeedback::lockedAnalysisFor($locked, $field, $recommendationId);
            $recommendation = $analysis?->recommendation($recommendationId);
            if ($analysis === null || $recommendation === null || $analysis->state() !== 'completada') {
                throw ValidationException::withMessages([
                    'recommendation' => 'La recomendación no corresponde a este campo y sílabo.',
                ]);
            }
            $suggestedText = (string) $recommendation['texto_sugerido'];
            $applied = AiHistoryEntry::query()
                ->session($analysis->sesion_id)
                ->ofType('decision')
                ->where('mensaje_ia->entrada->recomendacion_id', $recommendationId)
                ->where('mensaje_ia->entrada->decision', 'aplicada')
                ->exists();
            if ($applied) {
                return ['syllabus' => $locked, 'value' => $suggestedText];
            }
            if (! in_array($locked->estado, ['borrador', 'correccion_solicitada'], true)) {
                throw ValidationException::withMessages(['syllabus' => 'El sílabo ya no está en estado editable.']);
            }
            if ($locked->version_bloqueo !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'version_bloqueo' => 'El borrador cambió. Recarga y compara nuevamente antes de aplicar.',
                ]);
            }

            $fieldValue = FieldValue::query()
                ->where('silabo_id', $locked->id)
                ->where('definicion_campo_id', $field->id)
                ->first();
            $currentValue = $fieldValue === null ? '' : $fieldValue->valor;
            if (! is_string($currentValue)
                || ! hash_equals((string) $analysis->input('huella_contenido'), $this->hasher->hash($currentValue))) {
                throw ValidationException::withMessages([
                    'recommendation' => 'El contenido analizado cambió. Solicita una recomendación nueva.',
                ]);
            }
            $maxLength = $field->tipo === 'texto_corto' ? 1000 : 50000;
            if (mb_strlen($suggestedText) > $maxLength
                || preg_match('/<\/?[a-z][^>]*>/i', $suggestedText) === 1) {
                throw ValidationException::withMessages([
                    'recommendation' => 'El texto sugerido no cumple las reglas del campo.',
                ]);
            }

            $updated = $this->updateDraftField->execute(
                $locked,
                $field,
                ['version_bloqueo' => $expectedLockVersion, 'value' => $suggestedText],
                $actor,
                $request,
            );
            $activeRole = $this->roles->resolve($request);
            AiHistoryEntry::query()->create([
                'sesion_id' => $analysis->sesion_id,
                'mensaje_ia' => [
                    'tipo' => 'decision',
                    'estado' => 'registrada',
                    'entrada' => [
                        'analisis_id' => $analysis->id,
                        'recomendacion_id' => $recommendationId,
                        'usuario_id' => $actor->id,
                        'asignacion_rol_id' => $activeRole?->id,
                        'decision' => 'aplicada',
                        'contenido_antes' => $currentValue,
                        'contenido_despues' => $suggestedText,
                        'version_bloqueo_origen' => $expectedLockVersion,
                        'version_bloqueo_resultado' => $updated->version_bloqueo,
                    ],
                ],
            ]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'ia.recomendacion_aplicada',
                resourceType: 'recomendacion_ia',
                resourceId: $recommendationId,
                result: 'exito',
                metadata: [
                    'ai_execution_id' => $analysis->id,
                    'field_key' => $field->clave,
                    'before_fingerprint' => $this->hasher->hash($currentValue),
                    'after_fingerprint' => $this->hasher->hash($suggestedText),
                    'lock_version_origin' => $expectedLockVersion,
                    'lock_version_result' => $updated->version_bloqueo,
                ],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return ['syllabus' => $updated, 'value' => $suggestedText];
        });
    }
}
