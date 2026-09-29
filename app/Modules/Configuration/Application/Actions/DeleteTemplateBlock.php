<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;

class DeleteTemplateBlock
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    public function execute(TemplateBlock $block, User $actor, Request $request): void
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        $this->work->replace($request, function () use ($activeRole, $actor, $block, $request): void {

            $template = SyllabusTemplate::query()->whereKey($block->plantilla_id)->lockForUpdate()->firstOrFail();
            $lockedBlock = $template->blockOrFail($block->id);
            FieldDefinition::query()
                ->where('plantilla_id', $template->id)
                ->where('bloque_plantilla_id', $lockedBlock->id)
                ->delete();
            $template->removeBlockDocument($lockedBlock->id);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.bloque_eliminado',
                resourceType: 'bloque_plantilla',
                resourceId: $block->id,
                result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
        });
    }
}
