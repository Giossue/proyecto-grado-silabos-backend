<?php

namespace App\Modules\Identity\Domain\Enums;

enum RoleCode: string
{
    case Administrator = 'administrador';
    case Coordinator = 'coordinador';
    case Teacher = 'docente';
    case Librarian = 'bibliotecario';
    case Dean = 'decano';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Coordinator => 'Coordinador',
            self::Teacher => 'Docente',
            self::Librarian => 'Bibliotecario',
            self::Dean => 'Decano',
        };
    }

    public function hasCareerScope(): bool
    {
        return $this === self::Coordinator || $this === self::Teacher;
    }

    /** El decano actúa sobre las carreras de una facultad (I-173). */
    public function hasFacultyScope(): bool
    {
        return $this === self::Dean;
    }
}
