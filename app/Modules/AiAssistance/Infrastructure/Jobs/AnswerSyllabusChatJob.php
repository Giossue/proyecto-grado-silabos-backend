<?php

namespace App\Modules\AiAssistance\Infrastructure\Jobs;

use App\Modules\AiAssistance\Application\BuildSyllabusChatContext;
use App\Modules\AiAssistance\Domain\Contracts\AiChatGateway;
use App\Modules\AiAssistance\Domain\Data\AiChatInput;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
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
        $message = DB::transaction(function (): ?AiHistoryEntry {
            $message = AiHistoryEntry::query()->lockForUpdate()->find($this->messageId);
            if ($message === null || ! $message->isOpen()) {
                return null;
            }
            $message->amend(['estado' => 'en_ejecucion']);
            $job = JobExecution::query()->lockForUpdate()->find($message->inputString('ejecucion_trabajo_id'));
            $job?->update([
                'estado' => 'en_ejecucion', 'progreso' => 15,
                'intentos' => $job->intentos + 1, 'iniciado_en' => now(),
            ]);

            return $message;
        });
        if ($message === null) {
            return;
        }
        if ($message->input('context_version') !== BuildSyllabusChatContext::VERSION) {
            $this->finishFailed('contexto_ia_retirado', 'La solicitud pertenece al asistente anterior. Envíe otra pregunta para preparar las unidades.');

            return;
        }
        $question = AiHistoryEntry::query()->findOrFail($message->inputString('respuesta_a_id'))->input('contenido');
        $completed = AiHistoryEntry::query()
            ->session(['silabo_id' => $message->silabo_id, 'usuario_id' => $message->usuario_id])
            ->ofType('mensaje')
            ->whereKeyNot($message->id)
            ->where('contenido_mensaje->estado', 'completado')
            ->where('contenido_mensaje->entrada->context_version', BuildSyllabusChatContext::VERSION)
            ->latest('enviado_en')
            ->limit((int) config('ai.limits.chat_history_messages'))
            ->get()
            ->reverse();
        $history = [];
        foreach ($completed as $item) {
            $isUser = $item->part('rol') === 'usuario';
            $history[] = [
                'role' => $isUser ? 'user' : 'assistant',
                'content' => (string) ($isUser ? $item->input('contenido') : $item->part('contenido')),
            ];
        }
        $evidence = [];
        foreach ($message->evidence() as $item) {
            $evidence[] = [
                'id' => (string) $item['id'],
                'type' => (string) $item['tipo'],
                'label' => (string) $item['etiqueta'],
                'excerpt' => (string) $item['extracto'],
            ];
        }

        try {
            $result = $gateway->reply(new AiChatInput($message->id, (string) $question, 'es-EC', $history, $evidence));
            if (array_diff($result->citationIds, array_column($evidence, 'id')) !== []) {
                throw new AiContractException('La respuesta citó evidencia fuera del conjunto fijado.');
            }
            DB::transaction(function () use ($message, $result): void {
                $locked = AiHistoryEntry::query()->lockForUpdate()->findOrFail($message->id);
                if (! $locked->isOpen()) {
                    return;
                }
                $locked->amend([
                    'estado' => 'completado',
                    'contenido' => $result->answer,
                    'citas' => $result->citationIds,
                    'version_pasarela_ejecutada' => $result->gatewayVersion,
                    'completado_en' => now()->toIso8601String(),
                ]);
                JobExecution::query()->find($locked->inputString('ejecucion_trabajo_id'))?->update([
                    'estado' => 'completada', 'progreso' => 100,
                    'resultado' => ['message_id' => $locked->id, 'citation_count' => count($result->citationIds)],
                    'finalizado_en' => now(),
                ]);
            });
        } catch (AiContractException) {
            $this->finishFailed('contrato_ia_invalido', 'La respuesta de IA no pudo verificarse.');
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
            $chatMessage = AiHistoryEntry::query()->lockForUpdate()->find($this->messageId);
            if ($chatMessage === null || ! $chatMessage->isOpen()) {
                return;
            }
            $chatMessage->amend([
                'estado' => 'fallido', 'codigo_error' => $code,
                'mensaje_error' => $message, 'completado_en' => now()->toIso8601String(),
            ]);
            JobExecution::query()->find($chatMessage->inputString('ejecucion_trabajo_id'))?->update([
                'estado' => 'fallida', 'codigo_error' => $code,
                'mensaje_error' => $message, 'finalizado_en' => now(),
            ]);
        });
    }
}
