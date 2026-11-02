<?php

namespace App\Modules\Syllabus\Domain\Policies;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
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
        return $user->activo
            && $this->roles->hasRole(request(), RoleCode::Coordinator);
    }

    public function view(User $user, Syllabus $syllabus): bool
    {
        $activeRole = $this->roles->resolve(request());
        if (! $user->activo || $activeRole === null) {
            return false;
        }
        if ($this->isReviewer($activeRole, $syllabus)) {
            return true;
        }
        if ($activeRole->role->codigo_rol !== RoleCode::Teacher->value
            || $activeRole->carrera_id !== $syllabus->convocation()->value('carrera_id')) {
            return false;
        }

        return $this->hasCurrentCollaboration($user, $syllabus)
            || Syllabus::query()->whereKey($syllabus->getKey())->writtenBy($user)->exists();
    }

    public function start(User $user, Syllabus $syllabus): bool
    {
        return $this->canTeacherEdit($user, $syllabus) && $syllabus->estado === 'sin_iniciar';
    }

    public function edit(User $user, Syllabus $syllabus): bool
    {
        return $this->canTeacherEdit($user, $syllabus)
            && in_array($syllabus->estado, ['borrador', 'correccion_solicitada'], true);
    }

    public function submit(User $user, Syllabus $syllabus): bool
    {
        return $this->canTeacherEdit($user, $syllabus, enforceDeadline: false)
            && in_array($syllabus->estado, ['borrador', 'correccion_solicitada', 'en_revision'], true);
    }

    public function reset(User $user, Syllabus $syllabus): Response
    {
        if ($syllabus->estado !== 'aprobado' || ! $this->roles->hasRole(request(), RoleCode::Coordinator)) {
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

        return $user->activo && $activeRole !== null && $this->isReviewer($activeRole, $syllabus);
    }

    private function isReviewer(RoleAssignment $activeRole, Syllabus $syllabus): bool
    {
        $careerId = $syllabus->convocation()->value('carrera_id');

        return match ($activeRole->role->codigo_rol) {
            RoleCode::Coordinator->value => $activeRole->carrera_id === $careerId,
            RoleCode::Librarian->value => LibrarySubmission::query()
                ->whereHas('revision', fn ($revision) => $revision->where('silabo_id', $syllabus->id))
                ->exists(),
            default => false,
        };
    }

    private function canTeacherEdit(User $user, Syllabus $syllabus, bool $enforceDeadline = true): bool
    {
        if (! $this->isCurrentTeacher($user, $syllabus)) {
            return false;
        }
        $convocation = $syllabus->convocation()->running()->first();

        return $convocation !== null && (! $enforceDeadline || ! $convocation->deadlinePassed());
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
