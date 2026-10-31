<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantProposal;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DecideTemplateAssistantProposal
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function apply(TemplateAssistantProposal $proposal, User $actor, Request $request): TemplateAssistantProposal
    {
        return $this->decide($proposal, $actor, $request, TemplateAssistantProposal::APPLIED, 'plantilla.propuesta_ia_aplicada');
    }

    public function discard(TemplateAssistantProposal $proposal, User $actor, Request $request): TemplateAssistantProposal
    {
        return $this->decide($proposal, $actor, $request, TemplateAssistantProposal::DISCARDED, 'plantilla.propuesta_ia_descartada');
    }

    private function decide(TemplateAssistantProposal $proposal, User $actor, Request $request, string $state, string $action): TemplateAssistantProposal
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($action, $activeRole, $actor, $proposal, $state): TemplateAssistantProposal {
            $locked = TemplateAssistantProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            abort_unless($locked->solicitada_por === $actor->id, 404);
            if ($locked->estado !== TemplateAssistantProposal::PENDING) {
                throw ValidationException::withMessages(['proposal' => 'Esta propuesta ya no está pendiente.']);
            }
            $locked->update(['estado' => $state, 'decidida_por' => $actor->id, 'decidida_en' => now()]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: $action,
                resourceType: 'propuesta_ia_plantilla',
                resourceId: $locked->id,
                result: 'exito',
                metadata: ['changes' => count($locked->cambios['lista'])],
            );

            return $locked;
        });
    }
}
