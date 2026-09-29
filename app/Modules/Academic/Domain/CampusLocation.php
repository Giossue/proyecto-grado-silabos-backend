<?php

namespace App\Modules\Academic\Domain;

enum CampusLocation: string
{
    case Matriz = 'Campus Matriz Dr. Gabriel Galarza López';
    case Laguacoto = 'Campus Laguacoto';
    case Ceni = 'Campus CENI (Centro de Eventos Nacionales e Internacionales)';
    case SanMiguel = 'Extensión San Miguel';
    case Chimbo = 'Sede Chimbo';

    public function label(): string
    {
        return $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $campus): string => $campus->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $campus): array => [
            'value' => $campus->value,
            'label' => $campus->label(),
        ], self::cases());
    }
}
