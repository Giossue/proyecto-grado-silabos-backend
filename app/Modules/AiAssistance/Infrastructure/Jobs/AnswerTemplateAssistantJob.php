<?php

namespace App\Modules\AiAssistance\Infrastructure\Jobs;

use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistant;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantOutcome;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantMessage;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantProposal;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Support\Queue\RunsOnce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

#[UniqueFor(600)]
class AnswerTemplateAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnce, SerializesModels;

    public const HISTORY = 12;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue('ia');
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(TemplateAssistant $assistant): void
    {
        $message = DB::transaction(function (): ?TemplateAssistantMessage {
            $message = TemplateAssistantMessage::query()->lockForUpdate()->find($this->messageId);
            if ($message === null || ! $message->isOpen()) {
                return null;
            }
            $message->amend(['estado' => 'en_ejecucion']);

            return $message;
        });
        if ($message === null) {
            return;
        }
        $template = SyllabusTemplate::query()->findOrFail($message->plantilla_id);
        $questionId = (string) data_get($message->contenido_mensaje, 'entrada.respuesta_a_id');
        $question = TemplateAssistantMessage::query()->findOrFail($questionId);
        $outcome = $assistant->run(
            $template,
            $question->text(),
            $this->history($message, $questionId),
            TemplateAssistant::userTag($message->usuario_id),
            $message->draft(),
        );

        DB::transaction(function () use ($message, $outcome, $template): void {
            $locked = TemplateAssistantMessage::query()->lockForUpdate()->findOrFail($message->id);
            if (! $locked->isOpen()) {
                return;
            }
            $failed = $outcome->status === TemplateAssistantOutcome::FAILED;
            $locked->amend([
                'estado' => $failed ? 'fallido' : 'completado',
                'resultado' => $outcome->status,
                'contenido' => $failed ? '' : $outcome->message,
                'mensaje_error' => $failed ? $outcome->message : null,
                'codigo_error' => $outcome->errorCode,
                'traza' => $outcome->trace,
                'uso' => $outcome->usage,
                'pasos' => $outcome->steps,
                'version_pasarela_ejecutada' => $outcome->gatewayVersion,
                'completado_en' => now()->toIso8601String(),
            ]);
            if ($outcome->proposal === null) {
                return;
            }
            TemplateAssistantProposal::query()
                ->where('plantilla_id', $template->id)
                ->where('solicitada_por', $locked->usuario_id)
                ->where('estado', TemplateAssistantProposal::PENDING)
                ->update(['estado_propuesta' => TemplateAssistantProposal::REPLACED]);
            TemplateAssistantProposal::query()->create([
                'plantilla_id' => $template->id,
                'mensaje_ia_plantilla_id' => $locked->id,
                'solicitada_por' => $locked->usuario_id,
                'estructura' => $outcome->proposal['payload'],
                'cambios' => [
                    'lista' => $outcome->proposal['changes'],
                    'secciones' => $outcome->proposal['changed']['secciones'],
                    'campos' => $outcome->proposal['changed']['campos'],
                ],
                'huella_base' => $outcome->proposal['fingerprint'],
                'version_borrador' => (string) data_get($locked->contenido_mensaje, 'entrada.version_borrador', ''),
                'estado' => TemplateAssistantProposal::PENDING,
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function (): void {
            $message = TemplateAssistantMessage::query()->lockForUpdate()->find($this->messageId);
            if ($message === null || ! $message->isOpen()) {
                return;
            }
            $message->amend([
                'estado' => 'fallido',
                'resultado' => TemplateAssistantOutcome::FAILED,
                'codigo_error' => 'asistente_fallido',
                'mensaje_error' => 'El asistente no está disponible por ahora. Puede seguir editando la plantilla.',
                'completado_en' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function history(TemplateAssistantMessage $message, string $questionId): array
    {
        $history = [];
        $previous = TemplateAssistantMessage::query()
            ->conversation($message->plantilla_id, $message->usuario_id)
            ->whereNotIn('mensaje_ia_plantilla_id', [$message->id, $questionId])
            ->where('contenido_mensaje->estado', 'completado')
            ->latest('enviado_en')
            ->limit(self::HISTORY)
            ->get()
            ->reverse();
        foreach ($previous as $item) {
            $text = trim($item->text());
            if ($text !== '') {
                $history[] = ['role' => $item->role() === 'usuario' ? 'user' : 'assistant', 'content' => $text];
            }
        }

        return $history;
    }
}
