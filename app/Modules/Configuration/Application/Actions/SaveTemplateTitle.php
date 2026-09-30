<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;

final class SaveTemplateTitle
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    public function execute(SyllabusTemplate $template, string $title, User $actor, Request $request): void
    {
        abort_unless($actor->can('manage-templates'), 403);
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        $this->work->replace($request, function () use ($activeRole, $actor, $template, $title): void {
            $locked = SyllabusTemplate::query()
                ->whereKey($template->id)
                ->lockForUpdate()
                ->firstOrFail();
            $mapping = $locked->mapeo_documento ?? [];
            $mapping['title_block'] = ['text' => trim($title)];
            $locked->update(['mapeo_documento' => $mapping]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.titulo_actualizado',
                resourceType: 'plantilla_silabo',
                resourceId: $locked->id,
                result: 'exito',
            );
        });
    }
}
