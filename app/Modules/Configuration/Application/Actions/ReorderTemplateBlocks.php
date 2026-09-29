<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReorderTemplateBlocks
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    /** @param array<int, string> $blockIds */
    public function execute(
        SyllabusTemplate $template,
        string $sectionId,
        array $blockIds,
        User $actor,
        Request $request,
    ): void {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();

        $this->work->replace($request, function () use ($activeRole, $actor, $blockIds, $request, $sectionId, $template): void {
            $lockedTemplate = SyllabusTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

            $section = $lockedTemplate->sectionOrFail($sectionId);
            $blocks = $section->blocks();

            if ($blocks->count() !== count($blockIds) || $blocks->pluck('id')->sort()->values()->all() !== collect($blockIds)->sort()->values()->all()) {
                throw ValidationException::withMessages(['blocks' => 'El nuevo orden no es válido. Recargue la página e intente otra vez.']);
            }

            $lockedTemplate->reorderBlocks($sectionId, array_values($blockIds));

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'plantilla.bloques_reordenados',
                resourceType: 'plantilla_silabo',
                resourceId: $lockedTemplate->id,
                result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
        });
    }
}
