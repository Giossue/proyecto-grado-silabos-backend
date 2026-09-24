<?php

namespace App\Modules\Academic\Domain;

use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;

final class AcademicStructurePermissions
{
    /** @var list<string> */
    public const GOVERNANCE_ENTITIES = [
        'facultad',
        'carrera',
        'periodo',
    ];

    /** @var list<string> */
    public const CAREER_ENTITIES = [
        'asignatura',
        'programacion_asignatura',
        'paralelo',
        'asignacion_docente',
    ];

    /** @var list<string> */
    public const GOVERNANCE_STATUS_ENTITIES = [
        'facultad',
        'carrera',
    ];

    /** @var list<string> */
    public const GOVERNANCE_UPDATE_ENTITIES = [
        'facultad',
        'carrera',
        'periodo',
    ];

    /** @var list<string> */
    public const CAREER_UPDATE_ENTITIES = [
        'asignatura',
    ];

    /**
     * Lo que Coordinación puede crear por la ruta genérica. Programaciones y paralelos
     * se crean con PreparePeriod y CreateParallels, que validan el período completo.
     *
     * @var list<string>
     */
    public const CAREER_GENERIC_CREATE_ENTITIES = [
        'asignatura',
        'asignacion_docente',
    ];

    /** @var list<string> Lo que Coordinación puede eliminar por la ruta genérica. */
    public const CAREER_GENERIC_DELETE_ENTITIES = [
        'asignacion_docente',
    ];

    public static function isGovernanceContext(?RoleAssignment $activeRole): bool
    {
        return $activeRole?->role->codigo_rol === RoleCode::Administrator->value;
    }

    public static function isCareerContext(?RoleAssignment $activeRole): bool
    {
        return $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
            && $activeRole->carrera_id !== null
            && $activeRole->career?->activo === true;
    }

    public static function mayCreate(?RoleAssignment $activeRole, string $entity): bool
    {
        return (self::isGovernanceContext($activeRole) && in_array($entity, self::GOVERNANCE_ENTITIES, true))
            || (self::isCareerContext($activeRole) && in_array($entity, self::CAREER_ENTITIES, true));
    }

    public static function mayChangeStatus(?RoleAssignment $activeRole, string $entity): bool
    {
        // Coordinación no activa ni desactiva registros: la malla se edita y la asignación
        // docente se crea o se quita; el sílabo sigue al paralelo (I-130).
        return self::isGovernanceContext($activeRole) && in_array($entity, self::GOVERNANCE_STATUS_ENTITIES, true);
    }

    public static function mayUpdate(?RoleAssignment $activeRole, string $entity): bool
    {
        return (self::isGovernanceContext($activeRole)
            && in_array($entity, self::GOVERNANCE_UPDATE_ENTITIES, true))
            || (self::isCareerContext($activeRole)
                && in_array($entity, self::CAREER_UPDATE_ENTITIES, true));
    }
}
