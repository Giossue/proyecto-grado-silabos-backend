<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;

class DeleteTemplateSection
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    public function execute(TemplateSection $section, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        $this->work->replaceTemplate(function () use ($activeRole, $actor, $section): void {

            $template = SyllabusTemplate::query()->whereKey($section->plantilla_id)->lockForUpdate()->firstOrFail();
            $lockedSection = $template->sectionOrFail($section->id);
            $template->removeSectionDocument($lockedSection->id);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.seccion_eliminada',
                resourceType: 'seccion_plantilla',
                resourceId: $section->id,
                result: 'exito',
            );
        });
    }
}
