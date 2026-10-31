<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantAvailability;
use App\Modules\AiAssistance\Infrastructure\Jobs\AnswerTemplateAssistantJob;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantMessage;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SendTemplateAssistantMessage
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(User $actor, string $content, string $idempotencyKey, Request $request): TemplateAssistantMessage
    {
        if (! TemplateAssistantAvailability::available()) {
            throw ValidationException::withMessages(['message' => 'El asistente no está disponible por ahora.']);
        }
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($activeRole, $actor, $content, $idempotencyKey): TemplateAssistantMessage {
            $template = SyllabusTemplate::query()->lockForUpdate()->firstOrFail();
            $existing = TemplateAssistantMessage::query()
                ->where('plantilla_id', $template->id)
                ->where('usuario_id', $actor->id)
                ->where('contenido_mensaje->rol', 'usuario')
                ->where('contenido_mensaje->entrada->clave_idempotencia', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return TemplateAssistantMessage::query()
                    ->where('contenido_mensaje->entrada->respuesta_a_id', $existing->id)
                    ->firstOrFail();
            }
            $busy = TemplateAssistantMessage::query()
                ->conversation($template->id, $actor->id)
                ->whereIn('contenido_mensaje->estado', TemplateAssistantMessage::OPEN_STATES)
                ->exists();
            if ($busy) {
                throw ValidationException::withMessages(['message' => 'Espere a que el asistente termine la respuesta anterior.']);
            }
            $question = TemplateAssistantMessage::query()->create([
                'plantilla_id' => $template->id,
                'usuario_id' => $actor->id,
                'contenido_mensaje' => [
                    'rol' => 'usuario',
                    'estado' => 'completado',
                    'entrada' => ['contenido' => trim($content), 'clave_idempotencia' => $idempotencyKey],
                ],
            ]);
            $answer = TemplateAssistantMessage::query()->create([
                'id' => (string) Str::uuid(),
                'plantilla_id' => $template->id,
                'usuario_id' => $actor->id,
                'contenido_mensaje' => [
                    'rol' => 'asistente',
                    'estado' => 'pendiente',
                    'entrada' => ['respuesta_a_id' => $question->id],
                    'contenido' => '',
                ],
            ]);
            AnswerTemplateAssistantJob::dispatch($answer->id)->afterCommit();
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'ia.plantilla_solicitada',
                resourceType: 'mensaje_ia_plantilla',
                resourceId: $answer->id,
                result: 'exito',
            );

            return $answer->refresh();
        });
    }
}
