<?php

namespace App\Modules\Documents\Domain;

use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;

final class SigningStatement
{
    public const ACTIONS = [
        SyllabusSignature::ROLE_TEACHER => 'envio_docente',
        SyllabusSignature::ROLE_COORDINATION => 'aprobacion_coordinacion',
        SyllabusSignature::ROLE_LIBRARY => 'aprobacion_biblioteca',
    ];

    public static function for(string $syllabusId, string $role, string $fingerprint): string
    {
        return 'silabos-ueb|silabo:'.$syllabusId.'|accion:'.self::ACTIONS[$role].'|huella:'.$fingerprint;
    }
}
