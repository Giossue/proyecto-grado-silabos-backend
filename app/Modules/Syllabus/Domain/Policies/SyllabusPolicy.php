<?php

namespace App\Modules\Syllabus\Domain\Policies;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Syllabus\Application\BibliographyCorrection;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Auth\Access\Response;

class SyllabusPolicy
{
    public function __construct(private readonly ActiveRole $roles) {}

    public function viewAny(User $user): bool
    {
        return $user->activo && $this->roles->hasRole(request(), RoleCode::Teacher);
    }

    public function reviewAny(User $user): bool
    {
        return $user->activo && $this->roles->hasRole(request(), RoleCode::Coordinator);
    }

    public function view(User $user, Syllabus $syllabus): bool
    {
        $activeRole = $this->roles->resolve(request());
        if (! $user->activo || $activeRole === null) {
            return false;
        }
        if ($activeRole->role->codigo_rol === RoleCode::Coordinator->value) {
            return $activeRole->carrera_id === $syllabus->convocation()->value('carrera_id');
        }
        if ($activeRole->role->codigo_rol === RoleCode::Librarian->value) {
            return LibrarySubmission::query()
                ->whereHas('revision', fn ($revision) => $revision->where('silabo_id', $syllabus->id))
                ->exists();
        }
        if ($activeRole->role->codigo_rol !== RoleCode::Teacher->value
            || $activeRole->carrera_id !== $syllabus->convocation()->value('carrera_id')) {
            return false;
        }

        return $this->hasCurrentCollaboration($user, $syllabus);
    }

    public function start(User $user, Syllabus $syllabus): bool
    {
        return $this->canTeacherEdit($user, $syllabus) && $syllabus->estado === 'sin_iniciar';
    }

    public function edit(User $user, Syllabus $syllabus): bool
    {
        return $this->inLibraryCorrection($user, $syllabus) || ($this->canTeacherEdit($user, $syllabus)
            && in_array($syllabus->estado, ['borrador', 'correccion_solicitada'], true));
    }

    public function submit(User $user, Syllabus $syllabus): bool
    {
        return $this->inLibraryCorrection($user, $syllabus) || ($this->canTeacherEdit($user, $syllabus)
            && in_array($syllabus->estado, ['borrador', 'correccion_solicitada', 'en_revision'], true));
    }

    public function reset(User $user, Syllabus $syllabus): Response
    {
        if (! in_array($syllabus->estado, ['borrador', 'en_revision', 'correccion_solicitada'], true)) {
            return Response::deny();
        }

        return $this->decide($user, $syllabus);
    }

    public function decide(User $user, Syllabus $syllabus): Response
    {
        if (! $this->review($user, $syllabus)) {
            return Response::deny();
        }
        if (! self::convocationAllowsDecisions($syllabus->convocation()->value('estado'))) {
            return Response::deny('La convocatoria está cerrada: la revisión queda solo para consulta y no admite decisiones.');
        }

        return Response::allow();
    }

    public static function convocationAllowsDecisions(mixed $state): bool
    {
        return in_array($state, [Convocation::STATE_OPEN, Convocation::STATE_PAUSED], true);
    }

    public function review(User $user, Syllabus $syllabus): bool
    {
        $activeRole = $this->roles->resolve(request());

        return $user->activo
            && $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
            && $activeRole->carrera_id === $syllabus->convocation()->value('carrera_id');
    }

    private function canTeacherEdit(User $user, Syllabus $syllabus): bool
    {
        return $this->isCurrentTeacher($user, $syllabus)
            && $syllabus->convocation()->running()->exists();
    }

    private function inLibraryCorrection(User $user, Syllabus $syllabus): bool
    {
        return $syllabus->estado === 'correccion_solicitada'
            && $this->isCurrentTeacher($user, $syllabus)
            && BibliographyCorrection::requested($syllabus);
    }

    private function isCurrentTeacher(User $user, Syllabus $syllabus): bool
    {
        $activeRole = $this->roles->resolve(request());

        return $user->activo
            && $activeRole?->role->codigo_rol === RoleCode::Teacher->value
            && $activeRole->carrera_id === $syllabus->convocation()->value('carrera_id')
            && $this->hasCurrentCollaboration($user, $syllabus);
    }

    private function hasCurrentCollaboration(User $user, Syllabus $syllabus): bool
    {
        return Syllabus::query()->whereKey($syllabus->getKey())->whereCurrentTeacher($user)->exists();
    }
}
