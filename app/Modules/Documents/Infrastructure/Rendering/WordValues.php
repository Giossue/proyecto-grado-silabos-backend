<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Domain\FreeText;

/** Lectura tolerante de los valores del snapshot para escribirlos en Word. */
final class WordValues
{
    public static function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        // Texto libre (I-156): una línea por párrafo, elemento o fila.
        if (FreeText::isDocument($value)) {
            /** @var array<string, mixed> $value */
            return FreeText::plainText($value);
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
