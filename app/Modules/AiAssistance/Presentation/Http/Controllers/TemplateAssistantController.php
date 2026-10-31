<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\Actions\DecideTemplateAssistantProposal;
use App\Modules\AiAssistance\Application\TemplateAssistant\Actions\SendTemplateAssistantMessage;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateAssistantAvailability;
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
                    'status' => $message->proposal->estado,
                    'changes' => $message->proposal->cambios['lista'],
                    'draft_version' => $message->proposal->version_borrador,
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
            $request->draft(),
            $request->string('draft_version')->toString(),
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

        return response()->json([
            'id' => $proposal->id,
            'status' => $proposal->estado,
            'draft_version' => $proposal->version_borrador,
            'changes' => $proposal->cambios['lista'],
            'changed' => ['sections' => $proposal->cambios['secciones'], 'fields' => $proposal->cambios['campos']],
            'sections' => $proposal->estructura['sections'],
        ]);
    }

    public function apply(TemplateAssistantProposal $proposal, ManageTemplatesRequest $request, DecideTemplateAssistantProposal $action): JsonResponse
    {
        $action->apply($proposal, $this->actor($request), $request);

        return response()->json(['message' => 'Propuesta aplicada en el editor.']);
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
}
