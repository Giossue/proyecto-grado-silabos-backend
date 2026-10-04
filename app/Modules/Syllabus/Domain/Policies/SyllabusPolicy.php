<?php

namespace App\Modules\Syllabus\Domain\Policies;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
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

    /** Bandeja de revisión: coordinación por carrera y decanato por facultad (I-173). */
    public function reviewAny(User $user): bool
    {
        return $user->activo
            && ($this->roles->hasRole(request(), RoleCode::Coordinator) || $this->roles->hasRole(request(), RoleCode::Dean));
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

        return $this->hasCurrentCollaboration($user, $syllabus);
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
        return $this->canTeacherEdit($user, $syllabus)
            && in_array($syllabus->estado, ['borrador', 'correccion_solicitada', 'en_revision'], true);
    }

    public function reset(User $user, Syllabus $syllabus): Response
    {
        if (! in_array($syllabus->estado, ['borrador', 'en_revision', 'correccion_solicitada'], true)
            || ! $this->roles->hasRole(request(), RoleCode::Coordinator)) {
            return Response::deny();
        }

        return $this->decide($user, $syllabus);
    }

    public function delete(User $user, Syllabus $syllabus): Response
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

    /** Biblioteca, coordinación de la carrera o decanato de la facultad (I-173). */
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
            RoleCode::Dean->value => is_string($careerId)
                && Career::query()->whereKey($careerId)->value('facultad_id') === $activeRole->facultad_id,
            RoleCode::Librarian->value => LibrarySubmission::query()
                ->whereHas('revision', fn ($revision) => $revision->where('silabo_id', $syllabus->id))
                ->exists(),
            default => false,
        };
    }

    private function canTeacherEdit(User $user, Syllabus $syllabus): bool
    {
        return $this->isCurrentTeacher($user, $syllabus)
            && $syllabus->convocation()->running()->exists();
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
