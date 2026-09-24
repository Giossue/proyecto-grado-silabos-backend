<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

/** Lectura tolerante de los valores del snapshot para escribirlos en Word. */
final class WordValues
{
    /** @param list<array<string, mixed>> $rows */
    public static function sum(array $rows, string $key): string
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $value = $row[$key] ?? null;
            if (is_numeric($value)) {
                $sum += (float) $value;
            }
        }

        return $sum == floor($sum) ? (string) (int) $sum : rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.');
    }

    /** @return array<string, mixed> */
    public static function rowData(mixed $row): array
    {
        $data = is_array($row) ? ($row['data'] ?? $row) : null;

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    public static function cell(array $data, string $key): string
    {
        return self::display($data[$key] ?? null);
    }

    public static function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (! is_array($value)) {
            return '';
        }
        $parts = [];
        foreach ($value as $item) {
            $parts[] = is_scalar($item) || $item === null
                ? (string) ($item ?? '')
                : (json_encode($item, JSON_UNESCAPED_UNICODE) ?: '');
        }

        return implode(', ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    public static function string(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    /** @return list<array<string, mixed>> */
    public static function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
