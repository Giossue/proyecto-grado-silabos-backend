<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\Actions\SendSyllabusAiMessage;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\AiAssistance\Presentation\Http\AvatarHint;
use App\Modules\AiAssistance\Presentation\Http\Requests\SendAiChatMessageRequest;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatController extends Controller
{
    public function show(Syllabus $syllabus, Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->can('edit', $syllabus), 403);
        $session = AiHistoryEntry::chatSession($syllabus->id, $actor->id);
        $chat = AiHistoryEntry::query()
            ->session($session)
            ->whereIn('mensaje_ia->tipo', ['mensaje', 'ocultamiento'])
            ->orderBy('enviado_en')
            ->get();
        $review = AiHistoryEntry::query()
            ->session(AiHistoryEntry::reviewSession($syllabus->id))
            ->ofType('revision')
            ->latest('enviado_en')
            ->first();
        $executions = AiHistoryEntry::query()
            ->fieldAnalysesOf($syllabus->id)
            ->when(
                $review !== null,
                fn ($query) => $query->where('mensaje_ia->entrada->revision_id', $review?->id),
            )
            ->latest('enviado_en')
            ->limit(25)
            ->get();
        $fields = FieldDefinition::query()
            ->with('template')
            ->whereKey($executions->map(fn (AiHistoryEntry $execution): mixed => $execution->input('definicion_campo_id'))->unique()->values())
            ->get()
            ->keyBy('id');
        $decisions = AiHistoryEntry::query()
            ->where('sesion_id', 'like', "silabo:{$syllabus->id}:campo:%")
            ->ofType('decision')
            ->get()
            ->groupBy(fn (AiHistoryEntry $decision): string => (string) $decision->input('recomendacion_id'));
        $reviewResults = [];
        foreach ($executions as $execution) {
            $field = $fields->get($execution->input('definicion_campo_id'));
            $section = $field?->block?->section;
            if ($field === null || $section === null) {
                continue;
            }
            $evidence = collect($execution->evidence())->keyBy('id');
            $recommendations = [];
            foreach ($execution->recommendations() as $recommendation) {
                $citations = [];
                foreach ($recommendation['evidencia_ids'] ?? [] as $evidenceId) {
                    $item = $evidence->get($evidenceId);
                    if (is_array($item)) {
                        $citations[] = $this->citationPayload($item);
                    }
                }
                $own = $decisions->get((string) $recommendation['id'], collect());
                $recommendations[] = [
                    'id' => $recommendation['id'],
                    'title' => $recommendation['titulo'],
                    'explanation' => $recommendation['explicacion'],
                    'suggested_text' => $recommendation['texto_sugerido'],
                    'applied' => $own->contains(fn (AiHistoryEntry $decision): bool => $decision->input('decision') === 'aplicada'),
                    'decisions' => $own
                        ->filter(fn (AiHistoryEntry $decision): bool => $decision->input('usuario_id') === $actor->id)
                        ->map(fn (AiHistoryEntry $decision): mixed => $decision->input('decision'))
                        ->values()
                        ->all(),
                    'citations' => $citations,
                ];
            }
            $reviewResults[] = [
                'id' => $execution->id,
                'field_id' => $field->id,
                'field_label' => $field->displayName(),
                'section' => [
                    'id' => $section->id,
                    'title' => $section->titulo,
                ],
                'status' => $execution->state(),
                'stale' => (int) $execution->input('version_bloqueo_origen') !== $syllabus->version_bloqueo,
                'reason' => $execution->part('motivo_no_concluyente'),
                'error' => $execution->part('mensaje_error'),
                'recommendations' => $recommendations,
            ];
        }
        $messages = [];
        foreach ($chat as $message) {
            if ($message->type() === 'mensaje') {
                $messages[] = $this->messagePayload($message);
            }
        }
        $reviewTotal = (int) $review?->input('total_campos');
        $reviewCompleted = (int) $review?->part('campos_finalizados');

        return response()->json([
            'conversation' => $chat->isEmpty() ? null : [
                'id' => $session,
                'hidden' => $chat->last()->type() === 'ocultamiento',
                'messages' => $messages,
            ],
            'suggested_questions' => [
                '¿Qué partes del sílabo necesitan mayor claridad?',
                '¿Hay contradicciones entre las secciones?',
                '¿Qué debería revisar antes de enviarlo?',
            ],
            'available' => (string) config('ai.driver') !== 'disabled',
            'review' => $review === null ? null : [
                'id' => $review->id,
                'status' => $review->state(),
                'draft_version' => (int) $review->input('version_bloqueo_origen'),
                'stale' => (int) $review->input('version_bloqueo_origen') !== $syllabus->version_bloqueo,
                'total_fields' => $reviewTotal,
                'completed_fields' => $reviewCompleted,
                'fields_with_recommendations' => (int) $review->part('campos_con_recomendaciones'),
                'failed_fields' => (int) $review->part('campos_fallidos'),
                'progress' => $reviewTotal === 0 ? 0 : (int) floor(($reviewCompleted / $reviewTotal) * 100),
                'requested_at' => $review->enviado_en->toIso8601String(),
                'completed_at' => $review->part('completado_en'),
                'avatar' => AvatarHint::forReview(
                    $review->state(),
                    (int) $review->part('campos_con_recomendaciones'),
                    (int) $review->part('campos_fallidos'),
                ),
            ],
            'review_results' => $reviewResults,
        ]);
    }

    public function store(
        Syllabus $syllabus,
        SendAiChatMessageRequest $request,
        SendSyllabusAiMessage $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $message = $action->execute(
            $syllabus,
            $actor,
            $request->string('message')->toString(),
            $request->string('idempotency_key')->toString(),
            $request,
        );

        return response()->json([
            'message' => 'La pregunta quedó registrada.',
            'assistant_message_id' => $message->id,
        ], 202);
    }

    public function clear(Syllabus $syllabus, Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->can('edit', $syllabus), 403);
        $session = AiHistoryEntry::chatSession($syllabus->id, $actor->id);
        $last = AiHistoryEntry::query()->session($session)->latest('enviado_en')->first();
        if ($last !== null && $last->type() !== 'ocultamiento') {
            AiHistoryEntry::query()->create([
                'sesion_id' => $session,
                'mensaje_ia' => [
                    'tipo' => 'ocultamiento',
                    'estado' => 'registrada',
                    'entrada' => ['usuario_id' => $actor->id],
                ],
            ]);
        }

        return response()->json(['message' => 'La conversación se ocultó de la vista.']);
    }

    /** @return array<string, mixed> */
    private function messagePayload(AiHistoryEntry $message): array
    {
        $isUser = $message->part('rol') === 'usuario';
        $evidence = collect($message->evidence())->keyBy('id');
        $citations = [];
        foreach ((array) $message->part('citas') as $evidenceId) {
            $item = $evidence->get($evidenceId);
            if (is_array($item)) {
                $citations[] = $this->citationPayload($item);
            }
        }

        return [
            'id' => $message->id,
            'role' => $message->part('rol'),
            'status' => $message->state(),
            'content' => (string) ($isUser ? $message->input('contenido') : $message->part('contenido')),
            'draft_version' => (int) $message->input('version_bloqueo_origen'),
            'error' => $message->part('mensaje_error'),
            'created_at' => $message->enviado_en->toIso8601String(),
            'citations' => $citations,
            'avatar' => AvatarHint::forMessage((string) $message->part('rol'), $message->state()),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function citationPayload(array $evidence): array
    {
        return [
            'id' => $evidence['id'],
            'type' => $evidence['tipo'],
            'field_id' => $evidence['definicion_campo_id'] ?? null,
            'source_id' => $evidence['fuente_academica_id'] ?? null,
            'label' => $evidence['etiqueta'],
            'excerpt' => $evidence['extracto'],
        ];
    }
}
