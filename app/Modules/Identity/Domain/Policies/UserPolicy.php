<?php

namespace App\Modules\Identity\Domain\Policies;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;

class UserPolicy
{
    public function __construct(private readonly ActiveRole $roles) {}

    public function viewAny(User $actor): bool
    {
        return $actor->activo && $this->roles->hasRole(request(), RoleCode::Administrator);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function createCareerTeacher(User $actor): bool
    {
        $activeRole = $this->roles->resolve(request());

        return $actor->activo
            && $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
            && $activeRole->career?->activo === true;
    }

    public function view(User $actor, User $target): bool
    {
        return $this->viewAny($actor);
    }

    /** I-170: el Coordinador corrige nombre y correo solo de docentes vigentes de su carrera activa. */
    public function updateCareerTeacher(User $actor, User $target): bool
    {
        if (! $this->createCareerTeacher($actor) || ! $target->activo) {
            return false;
        }

        $careerId = $this->roles->resolve(request())?->carrera_id;

        if ($careerId === null || $target->roleAssignments()->effective()->where('rol', RoleCode::Administrator->value)->exists()) {
            return false;
        }

        return $target->roleAssignments()
            ->effective()
            ->where('rol', RoleCode::Teacher->value)
            ->where('carrera_id', $careerId)
            ->exists();
    }

    public function update(User $actor, User $target): bool
    {
        return $this->viewAny($actor) && $actor->id !== $target->id;
    }

    public function managePending(User $actor, User $target): bool
    {
        if (! $actor->activo || $actor->id === $target->id || ! $target->activo || $target->hasVerifiedEmail()) {
            return false;
        }

        $activeRole = $this->roles->resolve(request());
        if ($activeRole?->role->codigo_rol === RoleCode::Administrator->value) {
            return true;
        }

        return $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
            && $activeRole->carrera_id !== null
            && $target->roleAssignments()
                ->effective()
                ->where('rol', RoleCode::Teacher->value)
                ->where('carrera_id', $activeRole->carrera_id)
                ->exists();
    }

    public function updateProfileData(User $actor, User $target): bool
    {
        if (! $actor->activo) {
            return false;
        }

        $activeRole = $this->roles->resolve(request());

        return $activeRole?->role->codigo_rol === RoleCode::Administrator->value;
    }
}
