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

class SaveTemplateSection
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TemplateSection $section, array $data, User $actor, Request $request): TemplateSection
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        return $this->work->replaceTemplate(function () use ($activeRole, $actor, $data, $section): TemplateSection {
            $template = SyllabusTemplate::query()->whereKey($section->plantilla_id)->lockForUpdate()->firstOrFail();
            $section = $template->updateSectionDocument($section->id, [
                'titulo' => (string) $data['title'],
                'orientacion' => $data['orientation'] ?? $section->orientacion,
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.seccion_actualizada',
                resourceType: 'seccion_plantilla',
                resourceId: $section->id,
                result: 'exito',
            );

            return $section;
        });
    }
}
