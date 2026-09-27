<?php

namespace App\Modules\Academic\Application\Queries;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Support\CanonicalHasher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class SubjectLearningOutcomesViewData
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ProcessLocks $locks,
        private readonly CanonicalHasher $hasher,
    ) {}

    public function role(Request $request): RoleAssignment
    {
        $role = $this->roles->resolve($request);
        if (! $role instanceof RoleAssignment || ! AcademicStructurePermissions::isCareerContext($role)) {
            throw new AuthorizationException('Solo la coordinación activa puede gestionar los resultados de su carrera.');
        }

        return $role;
    }

    public function subject(string $subjectId, Request $request): Subject
    {
        return Subject::query()->where('carrera_id', $this->role($request)->carrera_id)->findOrFail($subjectId);
    }

    /** @return array<string, mixed> */
    public function forSubject(Subject $subject): array
    {
        $base = $subject->learningOutcomeBase;
        $snapshot = $base?->snapshot();
        $lockReason = $this->locks->careerLockReason($subject->carrera_id);

        return [
            'subject_id' => $subject->id,
            ...($snapshot ?? [
                'source_name' => '', 'source_locator' => '', 'outcomes' => [],
                'confirmed_at' => null, 'confirmed_by' => null,
            ]),
            'fingerprint' => $snapshot === null ? null : $this->hasher->hash($snapshot),
            'editable' => $lockReason === null,
            'lock_reason' => $lockReason,
        ];
    }
}
