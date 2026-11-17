<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Application\TemplateVersions;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClearSyllabusTemplate
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly SyllabusTemplateProvisioner $provisioner,
        private readonly TemplateVersions $versions,
    ) {}

    public function execute(SyllabusTemplate $template, User $actor, Request $request): SyllabusTemplate
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();
        $this->versions->ensureInitial($template);
        $cleared = DB::transaction(function () use ($template, $actor): SyllabusTemplate {
            $saved = $this->provisioner->clear($template);
            $this->versions->record($saved, $actor);

            return $saved;
        });
        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $activeRole?->id,
            action: 'plantilla.vaciada',
            resourceType: 'plantilla_silabo',
            resourceId: $cleared->id,
            result: 'exito',
        );

        return $cleared;
    }
}
