<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateDraft;
use App\Modules\AiAssistance\Application\TemplateAssistant\TemplateDraftValidator;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\TemplateAssistantProposal;
use App\Modules\Configuration\Application\Actions\ReplaceTemplateStructure;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DecideTemplateAssistantProposal
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly TemplateDraftValidator $validator,
        private readonly ReplaceTemplateStructure $replace,
    ) {}

    public function apply(TemplateAssistantProposal $proposal, User $actor, Request $request): TemplateAssistantProposal
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        $stale = false;
        $result = DB::transaction(function () use ($activeRole, $actor, $proposal, $request, &$stale): TemplateAssistantProposal {
            $locked = $this->pending($proposal, $actor);
            $template = SyllabusTemplate::query()->lockForUpdate()->findOrFail($locked->plantilla_id);
            if (TemplateDraft::fingerprint($template) !== $locked->huella_base) {
                $locked->update(['estado' => TemplateAssistantProposal::STALE]);
                $stale = true;

                return $locked;
            }
            $sections = $locked->estructura['sections'];
            $errors = $this->validator->errors(TemplateDraft::fromTemplate($template, $sections));
            if ($errors !== []) {
                throw ValidationException::withMessages(['proposal' => $errors]);
            }
            $this->replace->execute($template, $sections, $actor, $request);
            $locked->update(['estado' => TemplateAssistantProposal::APPLIED, 'decidida_por' => $actor->id, 'decidida_en' => now()]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.propuesta_ia_aplicada',
                resourceType: 'propuesta_ia_plantilla',
                resourceId: $locked->id,
                result: 'exito',
                metadata: ['changes' => count($locked->cambios['lista'])],
            );

            return $locked;
        });
        if ($stale) {
            throw ValidationException::withMessages(['proposal' => 'La plantilla cambió desde que se armó la propuesta. Pida una nueva al asistente.']);
        }

        return $result;
    }

    public function discard(TemplateAssistantProposal $proposal, User $actor, Request $request): TemplateAssistantProposal
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($activeRole, $actor, $proposal): TemplateAssistantProposal {
            $locked = $this->pending($proposal, $actor);
            $locked->update(['estado' => TemplateAssistantProposal::DISCARDED, 'decidida_por' => $actor->id, 'decidida_en' => now()]);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.propuesta_ia_descartada',
                resourceType: 'propuesta_ia_plantilla',
                resourceId: $locked->id,
                result: 'exito',
            );

            return $locked;
        });
    }

    private function pending(TemplateAssistantProposal $proposal, User $actor): TemplateAssistantProposal
    {
        $locked = TemplateAssistantProposal::query()->lockForUpdate()->findOrFail($proposal->id);
        abort_unless($locked->solicitada_por === $actor->id, 404);
        if ($locked->estado !== TemplateAssistantProposal::PENDING) {
            throw ValidationException::withMessages(['proposal' => 'Esta propuesta ya no está pendiente.']);
        }

        return $locked;
    }
}
