<?php

namespace App\Modules\AiAssistance\Infrastructure\Jobs;

use App\Modules\AiAssistance\Domain\Contracts\AiChatGateway;
use App\Modules\AiAssistance\Domain\Data\AiChatInput;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnswerSyllabusChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue('ia');
    }

    public function handle(AiChatGateway $gateway): void
    {
        $message = DB::transaction(function (): ?AiMessage {
            $message = AiMessage::query()->lockForUpdate()->find($this->messageId);
            if ($message === null || in_array($message->estado_mensaje_ia, ['completado', 'fallido'], true)) {
                return null;
            }
            $message->update(['estado_mensaje_ia' => 'en_ejecucion']);
            $message->jobExecution()->update([
                'estado' => 'en_ejecucion', 'progreso' => 15,
                'intentos' => DB::raw('intentos + 1'), 'iniciado_en' => now(),
            ]);

            return $message;
        });
        if ($message === null) {
            return;
        }
        $message->load(['conversation.messages', 'evidence']);
        $question = AiMessage::query()->findOrFail($message->respuesta_a_id)->contenido_mensaje_ia;
        $completed = $message->conversation->messages
            ->where('id', '!=', $message->id)
            ->where('estado_mensaje_ia', 'completado')
            ->take(-((int) config('ai.limits.chat_history_messages')));
        $history = [];
        foreach ($completed as $item) {
            $history[] = [
                'role' => $item->rol_mensaje_ia === 'usuario' ? 'user' : 'assistant',
                'content' => $item->contenido_mensaje_ia,
            ];
        }
        $evidence = [];
        foreach ($message->evidence as $item) {
            $evidence[] = [
                'id' => $item->id,
                'type' => $item->tipo_evidencia_mensaje_ia,
                'label' => $item->etiqueta_evidencia_mensaje_ia,
                'excerpt' => $item->extracto_evidencia_mensaje_ia,
            ];
        }

        try {
            $result = $gateway->reply(new AiChatInput($message->id, $question, 'es-EC', $history, $evidence));
            DB::transaction(function () use ($message, $result): void {
                $locked = AiMessage::query()->lockForUpdate()->findOrFail($message->id);
                if (in_array($locked->estado_mensaje_ia, ['completado', 'fallido'], true)) {
                    return;
                }
                $locked->update([
                    'estado_mensaje_ia' => 'completado',
                    'contenido_mensaje_ia' => $result->answer,
                    'version_pasarela_ejecutada' => $result->gatewayVersion,
                    'completado_en' => now(),
                ]);
                foreach ($result->citationIds as $ordinal => $evidenceId) {
                    DB::table('citas_mensaje_ia')->insert([
                        'mensaje_ia_id' => $locked->id,
                        'evidencia_mensaje_ia_id' => $evidenceId,
                        'ordinal_cita_mensaje_ia' => $ordinal + 1,
                    ]);
                }
                $locked->jobExecution()->update([
                    'estado' => 'completada', 'progreso' => 100,
                    'resultado' => ['message_id' => $locked->id, 'citation_count' => count($result->citationIds)],
                    'finalizado_en' => now(),
                ]);
            });
        } catch (AiContractException $exception) {
            $this->finishFailed('contrato_ia_invalido', 'La respuesta de IA no pudo verificarse.');
        } catch (AiGatewayUnavailable $exception) {
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->finishFailed(
            $exception instanceof AiGatewayUnavailable ? 'servicio_ia_no_disponible' : 'chat_ia_fallido',
            'La ayuda de IA no está disponible por el momento. Puede seguir editando el sílabo.',
        );
    }

    private function finishFailed(string $code, string $message): void
    {
        DB::transaction(function () use ($code, $message): void {
            $chatMessage = AiMessage::query()->lockForUpdate()->find($this->messageId);
            if ($chatMessage === null || in_array($chatMessage->estado_mensaje_ia, ['completado', 'fallido'], true)) {
                return;
            }
            $chatMessage->update([
                'estado_mensaje_ia' => 'fallido', 'codigo_error' => $code,
                'mensaje_error' => $message, 'completado_en' => now(),
            ]);
            $chatMessage->jobExecution()->update([
                'estado' => 'fallida', 'codigo_error' => $code,
                'mensaje_error' => $message, 'finalizado_en' => now(),
            ]);
        });
    }
}
