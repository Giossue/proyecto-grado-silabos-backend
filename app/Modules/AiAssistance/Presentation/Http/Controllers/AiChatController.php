<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\Actions\SendSyllabusAiMessage;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiConversation;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiExecution;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiMessage;
use App\Modules\AiAssistance\Presentation\Http\Requests\SendAiChatMessageRequest;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatController extends Controller
{
    public function show(Syllabus $syllabus, Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->can('edit', $syllabus), 403);
        $conversation = AiConversation::query()
            ->where('silabo_id', $syllabus->id)
            ->where('usuario_id', $actor->id)
            ->with(['messages.citations'])
            ->first();
        $executions = AiExecution::query()
            ->where('silabo_id', $syllabus->id)
            ->with(['field', 'recommendations.evidence', 'recommendations.feedback'])
            ->latest('solicitado_en')
            ->limit(25)
            ->get();
        $reviewResults = [];
        foreach ($executions as $execution) {
            $recommendations = [];
            foreach ($execution->recommendations as $recommendation) {
                $citations = [];
                foreach ($recommendation->evidence as $evidence) {
                    $citations[] = ['id' => $evidence->id, 'label' => $evidence->nombre_fuente, 'excerpt' => $evidence->extracto];
                }
                $recommendations[] = [
                    'id' => $recommendation->id,
                    'title' => $recommendation->titulo,
                    'explanation' => $recommendation->explicacion,
                    'suggested_text' => $recommendation->texto_sugerido,
                    'applied' => $recommendation->feedback->contains('decision', 'aplicada'),
                    'decisions' => $recommendation->feedback->where('usuario_id', $actor->id)->pluck('decision')->values()->all(),
                    'citations' => $citations,
                ];
            }
            $reviewResults[] = [
                'id' => $execution->id,
                'field_id' => $execution->definicion_campo_id,
                'field_label' => $execution->field->etiqueta,
                'status' => $execution->estado,
                'stale' => $execution->version_bloqueo_origen !== $syllabus->version_bloqueo,
                'reason' => $execution->motivo_no_concluyente,
                'error' => $execution->mensaje_error,
                'recommendations' => $recommendations,
            ];
        }
        $messages = [];
        if ($conversation !== null) {
            foreach ($conversation->messages as $message) {
                $messages[] = $this->messagePayload($message);
            }
        }

        return response()->json([
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'hidden' => $conversation->ocultada_en !== null,
                'messages' => $messages,
            ],
            'suggested_questions' => [
                '¿Qué partes del sílabo necesitan mayor claridad?',
                '¿Hay contradicciones entre las secciones?',
                '¿Qué debería revisar antes de enviarlo?',
            ],
            'available' => (string) config('ai.driver') !== 'disabled',
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
        AiConversation::query()
            ->where('silabo_id', $syllabus->id)
            ->where('usuario_id', $actor->id)
            ->update(['ocultada_en' => now()]);

        return response()->json(['message' => 'La conversación se ocultó de la vista.']);
    }

    /** @return array<string, mixed> */
    private function messagePayload(AiMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->rol_mensaje_ia,
            'status' => $message->estado_mensaje_ia,
            'content' => $message->contenido_mensaje_ia,
            'draft_version' => $message->version_bloqueo_origen,
            'error' => $message->mensaje_error,
            'created_at' => $message->created_at->toIso8601String(),
            'citations' => $this->citationPayloads($message),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function citationPayloads(AiMessage $message): array
    {
        $citations = [];
        foreach ($message->citations as $evidence) {
            $citations[] = [
                'id' => $evidence->id,
                'type' => $evidence->tipo_evidencia_mensaje_ia,
                'field_id' => $evidence->definicion_campo_id,
                'source_id' => $evidence->fuente_academica_id,
                'label' => $evidence->etiqueta_evidencia_mensaje_ia,
                'excerpt' => $evidence->extracto_evidencia_mensaje_ia,
            ];
        }

        return $citations;
    }
}
