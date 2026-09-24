<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\Actions\ApplyAiRecommendation;
use App\Modules\AiAssistance\Application\Actions\RecordAiFeedback;
use App\Modules\AiAssistance\Application\Actions\RequestAiAnalysis;
use App\Modules\AiAssistance\Application\Actions\RequestSyllabusAiReview;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
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

        $session = AiHistoryEntry::fieldSession($syllabus->id, $field->id);
        $executions = AiHistoryEntry::query()
            ->session($session)
            ->ofType('analisis')
            ->latest('enviado_en')
            ->limit(10)
            ->get();
        $decisions = AiHistoryEntry::query()
            ->session($session)
            ->ofType('decision')
            ->get()
            ->groupBy(fn (AiHistoryEntry $decision): string => (string) $decision->input('recomendacion_id'));

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
            'executions' => $executions->map(fn (AiHistoryEntry $execution): array => [
                'id' => $execution->id,
                'status' => $execution->state(),
                'requested_at' => $execution->enviado_en->toIso8601String(),
                'completed_at' => $execution->part('completado_en'),
                'analysis_label' => 'Análisis con asistencia de IA',
                'input_content' => (string) $execution->input('contenido'),
                'reason' => $this->reasonLabel($execution->part('motivo_no_concluyente')),
                'mensaje_error' => $execution->part('mensaje_error'),
                'evidence' => array_map(fn (array $evidence): array => $this->evidencePayload($evidence), $execution->evidence()),
                'recommendations' => array_map(function (array $recommendation) use ($actor, $decisions): array {
                    $own = $decisions->get((string) $recommendation['id'], collect());

                    return [
                        'id' => $recommendation['id'],
                        'type' => $recommendation['tipo'],
                        'title' => $recommendation['titulo'],
                        'explanation' => $recommendation['explicacion'],
                        'suggested_text' => $recommendation['texto_sugerido'],
                        'evidence_ids' => array_values($recommendation['evidencia_ids'] ?? []),
                        'my_decisions' => $own
                            ->filter(fn (AiHistoryEntry $decision): bool => $decision->input('usuario_id') === $actor->id)
                            ->map(fn (AiHistoryEntry $decision): mixed => $decision->input('decision'))
                            ->values(),
                        'applied' => $own->contains(fn (AiHistoryEntry $decision): bool => $decision->input('decision') === 'aplicada'),
                    ];
                }, $execution->recommendations()),
            ])->values(),
        ];

        return response()->json($payload);
    }

    private function nullableIdentifier(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{id: string, type: string, field_id: string|null, source_id: string|null, source: string, excerpt: string}
     */
    private function evidencePayload(array $evidence): array
    {
        return [
            'id' => (string) $evidence['id'],
            'type' => (string) $evidence['tipo'],
            'field_id' => $this->nullableIdentifier($evidence['definicion_campo_id'] ?? null),
            'source_id' => $this->nullableIdentifier($evidence['fuente_academica_id'] ?? null),
            'source' => (string) $evidence['etiqueta'],
            'excerpt' => (string) $evidence['extracto'],
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

        $total = (int) $review->input('total_campos');
        $completed = (int) $review->part('campos_finalizados');

        return response()->json([
            'message' => "Revisión de IA iniciada para {$total} campo(s)",
            'review' => [
                'id' => $review->id,
                'status' => $review->state(),
                'draft_version' => (int) $review->input('version_bloqueo_origen'),
                'total_fields' => $total,
                'completed_fields' => $completed,
                'progress' => $total === 0 ? 0 : (int) floor(($completed / $total) * 100),
            ],
        ], 202);
    }

    public function feedback(
        Syllabus $syllabus,
        FieldDefinition $field,
        string $recommendation,
        RecordAiFeedbackRequest $request,
        RecordAiFeedback $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->assertField($syllabus, $field);
        $this->assertRecommendation($syllabus, $field, $recommendation);
        $action->execute(
            $syllabus,
            $field,
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
        string $recommendation,
        ApplyAiRecommendationRequest $request,
        ApplyAiRecommendation $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->assertField($syllabus, $field);
        $this->assertRecommendation($syllabus, $field, $recommendation);
        $result = $action->execute(
            $syllabus,
            $field,
            $recommendation,
            $request->integer('version_bloqueo'),
            $actor,
            $request,
        );

        return response()->json([
            'message' => 'La recomendación se aplicó al campo después de verificar la versión del borrador.',
            'version_bloqueo' => $result['syllabus']->version_bloqueo,
            'value' => $result['value'],
        ]);
    }

    private function assertRecommendation(Syllabus $syllabus, FieldDefinition $field, string $recommendation): void
    {
        abort_unless(
            AiHistoryEntry::query()
                ->session(AiHistoryEntry::fieldSession($syllabus->id, $field->id))
                ->ofType('analisis')
                ->whereRaw("mensaje_ia->'recomendaciones' @> CAST(? AS jsonb)", [json_encode([['id' => $recommendation]])])
                ->exists(),
            404,
        );
    }

    private function assertField(Syllabus $syllabus, FieldDefinition $field): void
    {
        abort_unless(
            $field->ia_habilitada
            && $field->editable_docente
            && ! $field->heredado
            && in_array($field->tipo, ['texto_corto', 'texto_largo'], true),
            404,
        );
    }

    private function reasonLabel(mixed $reason): ?string
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
