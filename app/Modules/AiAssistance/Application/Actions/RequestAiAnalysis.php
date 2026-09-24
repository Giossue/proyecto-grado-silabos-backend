<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Application\BuildSyllabusChatContext;
use App\Modules\AiAssistance\Domain\Contracts\AiAnalysisGateway;
use App\Modules\AiAssistance\Infrastructure\Jobs\AnalyzeSyllabusFieldJob;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registra un análisis de campo como mensaje `analisis` en la sesión
 * `silabo:{id}:campo:{campo}` del historial de IA, con su evidencia fijada.
 */
class RequestAiAnalysis
{
    public function __construct(
        private readonly AiAnalysisGateway $gateway,
        private readonly BuildSyllabusChatContext $contextBuilder,
        private readonly CanonicalHasher $hasher,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(
        Syllabus $syllabus,
        FieldDefinition $field,
        string $idempotencyKey,
        User $actor,
        Request $request,
        ?string $syllabusReviewId = null,
    ): AiHistoryEntry {
        $existing = $this->existingIdempotent($syllabus->id, $field->id, $idempotencyKey, $actor, $syllabusReviewId);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($actor, $field, $idempotencyKey, $request, $syllabus, $syllabusReviewId): AiHistoryEntry {
            $locked = Syllabus::query()->with('convocation')->lockForUpdate()->findOrFail($syllabus->id);
            $existing = $this->existingIdempotent($locked->id, $field->id, $idempotencyKey, $actor, $syllabusReviewId);
            if ($existing !== null) {
                return $existing;
            }
            $this->assertEligible($locked, $field);

            $fieldValue = FieldValue::query()
                ->where('silabo_id', $locked->id)
                ->where('definicion_campo_id', $field->id)
                ->first();
            $content = $fieldValue === null ? '' : $fieldValue->valor;
            if (! is_string($content) || mb_strlen($content) > (int) config('ai.limits.input_characters')) {
                throw ValidationException::withMessages([
                    'field' => 'La asistencia solo admite texto de hasta 50 000 caracteres.',
                ]);
            }

            $context = $this->contextBuilder->build($locked, implode(' ', array_filter([
                $field->etiqueta,
                $field->clave,
                $content,
                json_encode($field->reglas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], 'is_string')), $field->id);
            $sourceSetFingerprint = $this->hasher->hash(array_map(fn (array $item): array => [
                'type' => $item['type'],
                'field_id' => $item['field_id'],
                'source_id' => $item['source_id'],
                'fingerprint' => $item['fingerprint'],
            ], $context));
            $contentFingerprint = $this->hasher->hash($content);
            $functionalKey = $this->hasher->hash([
                'content_fingerprint' => $contentFingerprint,
                'field_id' => $field->id,
                'field_rules' => $field->reglas,
                'template_id' => $field->plantilla_id,
                'source_set_fingerprint' => $sourceSetFingerprint,
                'gateway_version' => $this->gateway->version(),
                'instruction_version' => (string) config('ai.instruction_version'),
                'contract_version' => (string) config('ai.contract_version'),
                'locale' => 'es-EC',
                'syllabus_review_id' => $syllabusReviewId,
            ]);
            $session = AiHistoryEntry::fieldSession($locked->id, $field->id);
            $reusable = AiHistoryEntry::query()
                ->session($session)
                ->ofType('analisis')
                ->where('mensaje_ia->entrada->clave_funcional', $functionalKey)
                ->whereIn('mensaje_ia->estado', ['pendiente', 'en_ejecucion', 'completada', 'no_concluyente'])
                ->first();
            if ($reusable !== null) {
                $this->recordReuse($reusable, $field, $actor, $request);

                return $reusable;
            }

            $evidence = array_map(fn (array $item): array => [
                'id' => $item['id'],
                'tipo' => $item['type'],
                'definicion_campo_id' => $item['field_id'],
                'fuente_academica_id' => $item['source_id'],
                'documento_id' => $item['fragment_id'],
                'etiqueta' => $item['label'],
                'extracto' => $item['excerpt'],
                'huella' => $item['fingerprint'],
            ], $context);

            $activeRole = $this->roles->resolve($request);
            $analysisId = (string) Str::uuid();
            $correlationId = $request->attributes->getString('correlation_id');
            $jobExecution = JobExecution::query()->create([
                'tipo' => 'ia.analisis',
                'cola' => 'ia',
                'estado' => 'pendiente',
                'clave_idempotencia' => "ia.analisis:{$locked->id}:{$field->id}:{$idempotencyKey}",
                'correlacion_id' => Str::isUuid($correlationId) ? $correlationId : (string) Str::uuid(),
                'tipo_recurso' => 'ejecucion_ia',
                'recurso_id' => $analysisId,
                'intentos' => 0,
                'intentos_maximos' => 3,
                'progreso' => 0,
            ]);
            $analysis = AiHistoryEntry::query()->create([
                'id' => $analysisId,
                'sesion_id' => $session,
                'mensaje_ia' => [
                    'tipo' => 'analisis',
                    'estado' => 'pendiente',
                    'entrada' => [
                        'silabo_id' => $locked->id,
                        'definicion_campo_id' => $field->id,
                        'clave_campo' => $field->clave,
                        'revision_id' => $syllabusReviewId,
                        'clave_idempotencia' => $idempotencyKey,
                        'clave_funcional' => $functionalKey,
                        'ejecucion_trabajo_id' => $jobExecution->id,
                        'version_contrato' => (string) config('ai.contract_version'),
                        'version_instruccion' => (string) config('ai.instruction_version'),
                        'version_pasarela_solicitada' => $this->gateway->version(),
                        'idioma' => 'es-EC',
                        'contenido' => $content,
                        'huella_contenido' => $contentFingerprint,
                        'huella_conjunto_fuentes' => $sourceSetFingerprint,
                        'version_bloqueo_origen' => $locked->version_bloqueo,
                        'parametros' => [
                            'max_recomendaciones' => (int) config('ai.limits.recommendations'),
                            'max_evidencia' => (int) config('ai.limits.evidence_items'),
                            'max_caracteres_extracto' => (int) config('ai.limits.evidence_excerpt_characters'),
                        ],
                        'evidencia' => $evidence,
                        'solicitado_por' => $actor->id,
                        'asignacion_rol_id' => $activeRole?->id,
                    ],
                    'recomendaciones' => [],
                ],
            ]);

            AnalyzeSyllabusFieldJob::dispatch($analysis->id)->afterCommit();
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'ia.analisis_solicitado',
                resourceType: 'ejecucion_ia',
                resourceId: $analysis->id,
                result: 'exito',
                metadata: [
                    'field_key' => $field->clave,
                    'content_fingerprint' => $contentFingerprint,
                    'source_set_fingerprint' => $sourceSetFingerprint,
                    'evidence_count' => count($evidence),
                    'gateway_version' => $this->gateway->version(),
                ],
                correlationId: $jobExecution->correlacion_id,
            );

            return $analysis->refresh();
        });
    }

    private function assertEligible(Syllabus $syllabus, FieldDefinition $field): void
    {
        if (! in_array($syllabus->estado, ['borrador', 'correccion_solicitada'], true)) {
            throw ValidationException::withMessages(['syllabus' => 'El sílabo no está en estado editable.']);
        }
        if (! $field->ia_habilitada
            || ! $field->editable_docente
            || $field->heredado
            || ! in_array($field->tipo, ['texto_corto', 'texto_largo'], true)) {
            throw ValidationException::withMessages([
                'field' => 'La asistencia de IA no está habilitada para este campo.',
            ]);
        }
    }

    private function existingIdempotent(
        string $syllabusId,
        string $fieldId,
        string $key,
        User $actor,
        ?string $syllabusReviewId,
    ): ?AiHistoryEntry {
        $analysis = AiHistoryEntry::query()
            ->session(AiHistoryEntry::fieldSession($syllabusId, $fieldId))
            ->ofType('analisis')
            ->where('mensaje_ia->entrada->clave_idempotencia', $key)
            ->where('mensaje_ia->entrada->revision_id', $syllabusReviewId)
            ->first();
        if ($analysis !== null && $analysis->input('solicitado_por') !== $actor->id) {
            abort(403);
        }

        return $analysis;
    }

    private function recordReuse(AiHistoryEntry $analysis, FieldDefinition $field, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);
        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $activeRole?->id,
            action: 'ia.analisis_reutilizado',
            resourceType: 'ejecucion_ia',
            resourceId: $analysis->id,
            result: 'exito',
            metadata: ['field_key' => $field->clave],
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
    }
}
