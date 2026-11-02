<?php

namespace App\Modules\Library\Domain\Data;

final class CatalogItemType
{
    /** @var array<string, array{kind: string, format: string}> */
    private const TYPES = [
        'BK' => ['kind' => 'Libro', 'format' => 'Libro físico'],
        'REF' => ['kind' => 'Referencia', 'format' => 'Libro físico'],
        'TE' => ['kind' => 'Tesis', 'format' => 'Tesis física'],
        'CR' => ['kind' => 'Revista', 'format' => 'Revista física'],
    ];

    /** @return array{kind: string, format: string} */
    public static function of(string $code): array
    {
        return self::TYPES[strtoupper(trim($code))] ?? self::TYPES['BK'];
    }
}
