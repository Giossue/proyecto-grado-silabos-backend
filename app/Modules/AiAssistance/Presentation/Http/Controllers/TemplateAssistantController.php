<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\Actions\DecideTemplateAssistantProposal;
use App\Modules\AiAssistance\Application\TemplateAssistant\Actions\SendTemplateAssistantMessage;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantAvailability;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateDraft;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantMessage;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantProposal;
use App\Modules\AiAssistance\Presentation\Http\Requests\SendTemplateAssistantMessageRequest;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\JsonResponse;

class TemplateAssistantController extends Controller
{
    public const SUGGESTIONS = [
        'Agrega un campo «Competencias» en resultados de aprendizaje, que lo llene el docente.',
        'Mueve la bibliografía antes de la metodología.',
        '¿Qué campos llena Coordinación?',
    ];

    public function show(ManageTemplatesRequest $request, ProcessLocks $locks): JsonResponse
    {
        $actor = $this->actor($request);
        $template = SyllabusTemplate::query()->firstOrFail();
        $fingerprint = TemplateDraft::fingerprint($template);
        $messages = TemplateAssistantMessage::query()
            ->conversation($template->id, $actor->id)
            ->with('proposal')
            ->orderBy('enviado_en')
            ->get();

        return response()->json([
            'available' => TemplateAssistantAvailability::available(),
            'locked' => $locks->templateLockReason() !== null,
            'suggestions' => self::SUGGESTIONS,
            'messages' => $messages->map(fn (TemplateAssistantMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role(),
                'status' => $message->state(),
                'result' => $message->part('resultado'),
                'content' => $message->text(),
                'error' => $message->part('mensaje_error'),
                'created_at' => $message->enviado_en->toIso8601String(),
                'proposal' => $message->proposal === null ? null : [
                    'id' => $message->proposal->id,
                    'status' => $this->status($message->proposal, $fingerprint),
                    'changes' => $message->proposal->cambios['lista'],
                ],
            ])->values()->all(),
        ]);
    }

    public function store(SendTemplateAssistantMessageRequest $request, SendTemplateAssistantMessage $action): JsonResponse
    {
        $answer = $action->execute(
            $this->actor($request),
            $request->string('message')->toString(),
            $request->string('idempotency_key')->toString(),
            $request,
        );

        return response()->json(['message' => 'El pedido quedó registrado.', 'assistant_message_id' => $answer->id], 202);
    }

    public function clear(ManageTemplatesRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $template = SyllabusTemplate::query()->firstOrFail();
        $query = TemplateAssistantMessage::query()->conversation($template->id, $actor->id);
        if ((clone $query)->whereIn('contenido_mensaje->estado', TemplateAssistantMessage::OPEN_STATES)->exists()) {
            return response()->json(['message' => 'Espere a que el asistente termine la respuesta anterior.', 'errors' => ['chat' => ['Espere a que el asistente termine la respuesta anterior.']]], 422);
        }
        $query->update(['ocultado_en' => now()]);

        return response()->json(['message' => 'Nuevo chat iniciado.']);
    }

    public function proposal(TemplateAssistantProposal $proposal, ManageTemplatesRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless($proposal->solicitada_por === $actor->id, 404);
        $template = SyllabusTemplate::query()->findOrFail($proposal->plantilla_id);

        return response()->json([
            'id' => $proposal->id,
            'status' => $this->status($proposal, TemplateDraft::fingerprint($template)),
            'changes' => $proposal->cambios['lista'],
            'changed' => ['sections' => $proposal->cambios['secciones'], 'fields' => $proposal->cambios['campos']],
            'sections' => $proposal->estructura['sections'],
        ]);
    }

    public function apply(TemplateAssistantProposal $proposal, ManageTemplatesRequest $request, DecideTemplateAssistantProposal $action): JsonResponse
    {
        $action->apply($proposal, $this->actor($request), $request);

        return response()->json(['message' => 'Propuesta aplicada a la plantilla.']);
    }

    public function discard(TemplateAssistantProposal $proposal, ManageTemplatesRequest $request, DecideTemplateAssistantProposal $action): JsonResponse
    {
        $action->discard($proposal, $this->actor($request), $request);

        return response()->json(['message' => 'Propuesta descartada.']);
    }

    private function actor(ManageTemplatesRequest $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function status(TemplateAssistantProposal $proposal, string $fingerprint): string
    {
        return $proposal->estado === TemplateAssistantProposal::PENDING && $proposal->huella_base !== $fingerprint
            ? TemplateAssistantProposal::STALE
            : $proposal->estado;
    }
}
