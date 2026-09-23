<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\Actions\ApplyAiRecommendation;
use App\Modules\AiAssistance\Application\Actions\RecordAiFeedback;
use App\Modules\AiAssistance\Application\Actions\RequestAiAnalysis;
use App\Modules\AiAssistance\Application\Actions\RequestSyllabusAiReview;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiEvidence;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiExecution;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiRecommendation;
use App\Modules\AiAssistance\Presentation\Http\Requests\ApplyAiRecommendationRequest;
use App\Modules\AiAssistance\Presentation\Http\Requests\RecordAiFeedbackRequest;
use App\Modules\AiAssistance\Presentation\Http\Requests\RequestAiAnalysisRequest;
use App\Modules\AiAssistance\Presentation\Http\Requests\RequestSyllabusAiReviewRequest;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistanceController extends Controller
{
    public function show(Syllabus $syllabus, FieldDefinition $field, Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('edit', $syllabus) === true, 403);
        $this->assertField($syllabus, $field);
        $actor = $request->user();
        $fieldValue = FieldValue::query()
            ->where('silabo_id', $syllabus->id)
            ->where('definicion_campo_id', $field->id)
            ->first();
        $current = $fieldValue === null ? '' : $fieldValue->valor;

        $executions = AiExecution::query()
            ->where('silabo_id', $syllabus->id)
            ->where('definicion_campo_id', $field->id)
            ->with([
                'evidence',
                'recommendations.evidence',
                'recommendations.feedback',
            ])
            ->latest('solicitado_en')
            ->limit(10)
            ->get();

        $payload = [
            'syllabus' => [
                'id' => $syllabus->id,
                'subject' => $syllabus->subject()->valueOrFail('nombre'),
                'state' => $syllabus->estado,
                'version_bloqueo' => $syllabus->version_bloqueo,
            ],
            'field' => [
                'id' => $field->id,
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'content' => is_string($current) ? $current : '',
            ],
            'environment' => [
                'is_provisional_simulator' => (string) config('ai.driver') === 'baseline',
            ],
            'executions' => $executions->map(fn (AiExecution $execution): array => [
                'id' => $execution->id,
                'status' => $execution->estado,
                'requested_at' => $execution->solicitado_en->toIso8601String(),
                'completed_at' => $execution->completado_en?->toIso8601String(),
                'analysis_label' => 'Análisis con asistencia de IA',
                'input_content' => $execution->contenido_entrada,
                'reason' => $this->reasonLabel($execution->motivo_no_concluyente),
                'mensaje_error' => $execution->mensaje_error,
                'evidence' => $execution->evidence
                    ->map(fn (AiEvidence $evidence): array => $this->evidencePayload($evidence))
                    ->values()
                    ->all(),
                'recommendations' => $execution->recommendations->map(fn (AiRecommendation $recommendation): array => [
                    'id' => $recommendation->id,
                    'type' => $recommendation->tipo,
                    'title' => $recommendation->titulo,
                    'explanation' => $recommendation->explicacion,
                    'suggested_text' => $recommendation->texto_sugerido,
                    'evidence_ids' => $recommendation->evidence->pluck('id')->values(),
                    'my_decisions' => $recommendation->feedback
                        ->where('usuario_id', $actor->id)
                        ->pluck('decision')
                        ->values(),
                    'applied' => $recommendation->feedback->contains('decision', 'aplicada'),
                ])->values(),
            ])->values(),
        ];

        return response()->json($payload);
    }

    private function nullableIdentifier(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{id: string, type: string, field_id: string|null, source_id: string|null, source: string, excerpt: string}
     */
    private function evidencePayload(AiEvidence $evidence): array
    {
        return [
            'id' => $evidence->id,
            'type' => $evidence->tipo_evidencia_ia,
            'field_id' => $this->nullableIdentifier($evidence->definicion_campo_id),
            'source_id' => $this->nullableIdentifier($evidence->fuente_academica_id),
            'source' => $evidence->etiqueta_evidencia_ia,
            'excerpt' => $evidence->extracto,
        ];
    }

    public function store(
        Syllabus $syllabus,
        FieldDefinition $field,
        RequestAiAnalysisRequest $request,
        RequestAiAnalysis $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $syllabus,
            $field,
            $request->string('idempotency_key')->toString(),
            $actor,
            $request,
        );

        return response()->json([
            'message' => 'La solicitud quedó registrada. Puede continuar editando mientras se procesa.',
        ], 202);
    }

    public function review(
        Syllabus $syllabus,
        RequestSyllabusAiReviewRequest $request,
        RequestSyllabusAiReview $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $review = $action->execute(
            $syllabus,
            $request->integer('version_bloqueo'),
            $request->string('idempotency_key')->toString(),
            $actor,
            $request,
        );

        return response()->json([
            'message' => "Revisión de IA iniciada para {$review->total_campos} campo(s)",
            'review' => [
                'id' => $review->id,
                'status' => $review->estado_revision_ia,
                'draft_version' => $review->version_bloqueo_origen,
                'total_fields' => $review->total_campos,
                'completed_fields' => $review->campos_finalizados,
                'progress' => $review->total_campos === 0
                    ? 0
                    : (int) floor(($review->campos_finalizados / $review->total_campos) * 100),
            ],
        ], 202);
    }

    public function feedback(
        Syllabus $syllabus,
        FieldDefinition $field,
        AiRecommendation $recommendation,
        RecordAiFeedbackRequest $request,
        RecordAiFeedback $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->assertField($syllabus, $field);
        abort_unless($recommendation->definicion_campo_id === $field->id, 404);
        $action->execute(
            $syllabus,
            $recommendation,
            $request->string('decision')->toString(),
            $actor,
            $request,
        );

        return response()->json(['message' => 'Decisión registrada sin modificar el borrador.']);
    }

    public function apply(
        Syllabus $syllabus,
        FieldDefinition $field,
        AiRecommendation $recommendation,
        ApplyAiRecommendationRequest $request,
        ApplyAiRecommendation $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->assertField($syllabus, $field);
        abort_unless($recommendation->definicion_campo_id === $field->id, 404);
        $updated = $action->execute(
            $syllabus,
            $recommendation,
            $request->integer('version_bloqueo'),
            $actor,
            $request,
        );

        return response()->json([
            'message' => 'La recomendación se aplicó al campo después de verificar la versión del borrador.',
            'version_bloqueo' => $updated->version_bloqueo,
            'value' => $recommendation->texto_sugerido,
        ]);
    }

    private function assertField(Syllabus $syllabus, FieldDefinition $field): void
    {
        abort_unless(
            $field->plantilla_id === $syllabus->plantilla_id
            && $field->ia_habilitada
            && $field->editable_docente
            && ! $field->heredado
            && in_array($field->tipo, ['texto_corto', 'texto_largo'], true),
            404,
        );
    }

    private function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'limite_evidencia_excedido' => 'El conjunto de evidencia excede el límite técnico seguro.',
            'evidencia_insuficiente' => 'No hay evidencia activa suficiente para analizar.',
            'contenido_vacio' => 'El campo todavía no contiene texto para analizar.',
            'sin_cambio_editorial' => 'No se identificó un cambio editorial verificable.',
            null => null,
            default => 'La evidencia disponible no permite producir una recomendación verificable.',
        };
    }
}
