<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Domain\Enums\RoleCode;

/**
 * Administración y Biblioteca no pueden ser docentes ni coordinadores, en ningún orden.
 * Se valida en la aplicación al asignar un rol; la base no lo restringe.
 */
final class RoleCombination
{
    public const MESSAGE = 'Administración y Biblioteca no pueden tener los roles Docente ni Coordinador.';

    /** @param iterable<mixed> $currentRoles códigos de los roles efectivos de la cuenta */
    public static function conflicts(RoleCode $incoming, iterable $currentRoles): bool
    {
        foreach ($currentRoles as $current) {
            $current = $current instanceof RoleCode ? $current : RoleCode::tryFrom((string) $current);
            if ($current !== null && self::isInstitutional($incoming) !== self::isInstitutional($current)) {
                return true;
            }
        }

        return false;
    }

    private static function isInstitutional(RoleCode $role): bool
    {
        return $role === RoleCode::Administrator || $role === RoleCode::Librarian;
    }
}
