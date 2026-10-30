<?php

namespace App\Support\Search;

final class AccentInsensitive
{
    private const FROM = 'áéíóúüñÁÉÍÓÚÜÑ';

    private const TO = 'aeiouunAEIOUUN';

    public static function pattern(string $term): string
    {
        return '%'.addcslashes(self::fold($term), '%_\\').'%';
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function matches(string $column): string
    {
        return 'translate('.$column.", '".self::FROM."', '".self::TO."') ILIKE ?";
    }

    private static function fold(string $term): string
    {
        return strtr($term, array_combine(mb_str_split(self::FROM), mb_str_split(self::TO)));
    }
}
