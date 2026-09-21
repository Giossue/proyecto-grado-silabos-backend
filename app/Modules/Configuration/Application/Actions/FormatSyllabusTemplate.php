<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;

/** Aplica el formato oficial a una plantilla que aún no tiene estructura. */
final class FormatSyllabusTemplate
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly SyllabusTemplateProvisioner $provisioner,
    ) {}

    public function execute(SyllabusTemplate $template, User $actor, Request $request): SyllabusTemplate
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();
        $formatted = $this->provisioner->format($template);

        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $activeRole?->id,
            action: 'plantilla.formateada',
            resourceType: 'plantilla_silabo',
            resourceId: $formatted->id,
            result: 'exito',
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );

        return $formatted;
    }
}
