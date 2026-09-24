<?php

namespace App\Modules\Academic\Application\Actions;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Alcance compartido por la edición y el borrado de un paralelo desde Coordinación. */
final class ParallelScope
{
    public static function careerId(?RoleAssignment $role): string
    {
        if (! $role instanceof RoleAssignment
            || $role->carrera_id === null
            || ! AcademicStructurePermissions::mayCreate($role, 'paralelo')) {
            throw new AuthorizationException('No puede modificar paralelos con el rol activo.');
        }

        return $role->carrera_id;
    }

    public static function roleId(?RoleAssignment $role): string
    {
        return (string) $role?->id;
    }

    public static function find(string $parallelId, string $careerId): Parallel
    {
        return Parallel::query()
            ->whereKey($parallelId)
            ->whereHas('scheduledSubject.subject', fn ($query) => $query->where('carrera_id', $careerId))
            ->lockForUpdate()
            ->firstOrFail();
    }

    public static function assertWithoutSyllabus(Parallel $parallel, string $message): void
    {
        if (Syllabus::query()->where('paralelo_id', $parallel->id)->exists()) {
            throw ValidationException::withMessages(['record' => $message]);
        }
    }
}
