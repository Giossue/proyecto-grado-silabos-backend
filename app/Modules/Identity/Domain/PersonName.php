<?php

namespace App\Modules\Identity\Domain;

final class PersonName
{
    public static function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtoupper($collapsed, 'UTF-8');
    }

    public static function hasFourParts(string $value): bool
    {
        return count(array_filter(preg_split('/\s+/u', trim($value)) ?: [])) >= 4;
    }
}
