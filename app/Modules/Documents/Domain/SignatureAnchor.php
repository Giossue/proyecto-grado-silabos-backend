<?php

namespace App\Modules\Documents\Domain;

final class SignatureAnchor
{
    public const LABELS = [
        'docente' => 'Firma del docente',
        'coordinacion' => 'Firma de coordinación',
        'biblioteca' => 'Firma de biblioteca',
    ];

    public const ROLES = [
        'docente' => 'Docente',
        'coordinacion' => 'Coordinación',
        'biblioteca' => 'Biblioteca',
    ];

    private const BLANK_LINES = 4;

    public static function text(string $role): string
    {
        return str_repeat("\n", self::BLANK_LINES).self::LABELS[$role];
    }

    public static function stamp(string $role, string $name, string $signedOn, string $serial, string $issuer): string
    {
        return "\n\nFirmado electrónicamente por {$name} · ".self::ROLES[$role]." · {$signedOn} · certificado N.º {$serial} · {$issuer}\n".self::LABELS[$role];
    }
}
