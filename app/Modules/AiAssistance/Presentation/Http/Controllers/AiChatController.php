<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\Actions\SendSyllabusAiMessage;
use App\Modules\AiAssistance\Application\SyllabusAiLimits;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\AiAssistance\Presentation\Http\AvatarHint;
use App\Modules\AiAssistance\Presentation\Http\Requests\SendAiChatMessageRequest;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiChatController extends Controller
{
    public function show(Syllabus $syllabus, Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->can('edit', $syllabus), 403);
        $session = AiHistoryEntry::chatSession($syllabus->id, $actor->id);
        $chat = AiHistoryEntry::query()
            ->session($session)
            ->whereIn('contenido_mensaje->tipo', ['mensaje', 'ocultamiento'])
            ->orderBy('enviado_en')
            ->get();
        // Cada «Nuevo chat» deja una marca de ocultamiento: solo se muestra la conversación actual.
        $messages = [];
        foreach ($chat as $message) {
            if ($message->type() === 'ocultamiento') {
                $messages = [];
            } elseif ($message->type() === 'mensaje') {
                $messages[] = $this->messagePayload($message);
            }
        }
        $limits = SyllabusAiLimits::usage($chat);

        return response()->json([
            'conversation' => $chat->isEmpty() ? null : [
                'id' => $session,
                'hidden' => $chat->last()->type() === 'ocultamiento',
                'messages' => $messages,
            ],
            'limits' => $limits,
            'suggested_questions' => [
                'Propón contenidos para las unidades de esta asignatura.',
                'Sugiere actividades para los resultados de aprendizaje confirmados.',
                'Ayúdame a organizar los temas de una unidad.',
            ],
            'available' => (string) config('ai.driver') === 'baseline'
                || (in_array((string) config('ai.driver'), ['openai', 'claude', 'anthropic', 'deepseek'], true)
                    && filled(config('ai.hosted.base_url')) && filled(config('ai.hosted.model')) && filled(config('ai.hosted.api_key'))),
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
        $chat = AiHistoryEntry::query()->session($session)->whereIn('contenido_mensaje->tipo', ['mensaje', 'ocultamiento'])->orderBy('enviado_en')->get();
        $last = $chat->last();
        if ($last !== null && $last->type() !== 'ocultamiento') {
            if (SyllabusAiLimits::usage($chat)['sessions_used'] >= SyllabusAiLimits::MAX_SESSIONS) {
                throw ValidationException::withMessages(['chat' => 'Ya usó los '.SyllabusAiLimits::MAX_SESSIONS.' chats permitidos para este sílabo.']);
            }
            AiHistoryEntry::query()->create([
                ...$session,
                'contenido_mensaje' => [
                    'tipo' => 'ocultamiento',
                    'estado' => 'registrada',
                    'entrada' => ['usuario_id' => $actor->id],
                ],
            ]);
        }

        return response()->json(['message' => 'Nuevo chat iniciado.']);
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
