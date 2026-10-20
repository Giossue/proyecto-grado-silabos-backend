<?php

namespace App\Modules\Configuration\Domain\Blocks;

final class Numbers
{
    public static function parse(mixed $text): float
    {
        return is_string($text) || is_numeric($text) ? (float) str_replace(',', '.', trim((string) $text)) : 0.0;
    }

    public static function total(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public static function plain(float|int $value): string
    {
        return (string) $value;
    }
}
