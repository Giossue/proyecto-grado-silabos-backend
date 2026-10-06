<?php

namespace App\Modules\Documents\Domain;

final class SignatureAnchor
{
    public const LABELS = [
        'docente' => 'Firma del docente',
        'coordinacion' => 'Firma de coordinación',
        'biblioteca' => 'Firma de biblioteca',
    ];

    private const BLANK_LINES = 4;

    public static function text(string $role): string
    {
        return str_repeat("\n", self::BLANK_LINES).self::LABELS[$role];
    }

    /** @return list<string> */
    public static function words(string $role): array
    {
        return explode(' ', self::LABELS[$role] ?? '');
    }
}
