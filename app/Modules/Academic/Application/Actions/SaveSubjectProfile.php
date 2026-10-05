<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectProfile;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaveSubjectProfile
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ProcessLocks $locks,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function authorize(Subject $subject, Request $request): void
    {
        $role = $this->roles->resolve($request);
        abort_unless($request->user()?->activo === true && (
            AcademicStructurePermissions::isGovernanceContext($role)
            || (AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id === $subject->carrera_id)
        ), 403);
    }

    /** @param array<string, mixed> $data */
    public function execute(Subject $subject, array $data, User $actor, Request $request): SubjectProfile
    {
        return DB::transaction(function () use ($actor, $data, $request, $subject): SubjectProfile {
            $lockedSubject = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $this->authorize($lockedSubject, $request);
            $this->locks->assertCareerEditable($lockedSubject->carrera_id);
            $profile = SubjectProfile::query()->firstOrNew(['asignatura_id' => $lockedSubject->id]);
            $profile->fill($data);
            $profile->save();
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'academico.ficha_asignatura.guardada',
                resourceType: 'ficha_asignatura',
                resourceId: $profile->id,
                result: 'exito',
                metadata: ['asignatura_id' => $lockedSubject->id],
            );

            return $profile;
        });
    }
}
