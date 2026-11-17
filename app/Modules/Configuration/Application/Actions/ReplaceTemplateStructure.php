<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Application\TemplateVersions;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReplaceTemplateStructure
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly SyllabusTemplateProvisioner $provisioner,
        private readonly TemplateVersions $versions,
    ) {}

    /**
     * @param  list<array{key: string, title: string, orientation?: string, fields?: list<array{key: string, label?: string|null, quien_llena: string, content: array<string, mixed>}>}>  $sections
     */
    public function execute(SyllabusTemplate $template, array $sections, User $actor, Request $request, ?TemplateDefinition $definition = null): SyllabusTemplate
    {
        $activeRole = $this->roles->resolve($request);
        $this->locks->assertTemplateEditable();
        $this->versions->ensureInitial($template);
        $replaced = DB::transaction(function () use ($template, $actor, $sections, $definition): SyllabusTemplate {
            $saved = $this->provisioner->replaceStructure($template, $sections, $definition);
            $this->versions->record($saved, $actor);

            return $saved;
        });
        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $activeRole?->id,
            action: 'plantilla.importada',
            resourceType: 'plantilla_silabo',
            resourceId: $replaced->id,
            result: 'exito',
            metadata: ['sections' => count($sections)],
        );

        return $replaced;
    }
}
