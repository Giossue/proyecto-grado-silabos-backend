<?php

namespace App\Modules\Identity\Domain;

final class PersonName
{
    public static function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtoupper($collapsed, 'UTF-8');
    }
}
