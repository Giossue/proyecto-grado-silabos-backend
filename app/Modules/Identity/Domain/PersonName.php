<?php

namespace App\Modules\Identity\Domain;

final class PersonName
{
    /** Letras (con tildes y ñ) separadas por un espacio, apóstrofo o guion: «María José», «De la Cruz-O'Neil». */
    public const PART_PATTERN = "/^[\\p{L}\\p{M}]+(?:[ '’-][\\p{L}\\p{M}]+)*$/u";

    public static function normalize(string $value): string
    {
        return mb_strtoupper(self::collapse($value), 'UTF-8');
    }

    public static function collapse(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    public static function isValidPart(string $value): bool
    {
        return preg_match(self::PART_PATTERN, self::collapse($value)) === 1;
    }

    /** Un nombre completo guardado: al menos un nombre y un apellido, solo con letras. */
    public static function isValidFullName(string $value): bool
    {
        return self::isValidPart($value) && count(explode(' ', self::collapse($value))) >= 2;
    }
}
