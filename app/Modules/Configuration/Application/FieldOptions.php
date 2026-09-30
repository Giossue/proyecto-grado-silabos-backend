<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

final class FieldOptions
{
    /** @return list<array{value: string, label: string}> */
    public static function options(FieldDefinition $field): array
    {
        $normalized = [];
        foreach ($field->opciones ?? [] as $option) {
            if (is_string($option) || is_int($option)) {
                $value = (string) $option;
                $label = $value;
            } elseif (is_array($option) && (is_string($option['value'] ?? null) || is_int($option['value'] ?? null))) {
                $value = (string) $option['value'];
                $label = is_string($option['label'] ?? null) ? $option['label'] : $value;
            } else {
                continue;
            }
            if (trim($value) !== '' && ! isset($normalized[$value])) {
                $normalized[$value] = ['value' => $value, 'label' => $label];
            }
        }

        return array_values($normalized);
    }

    /** @return list<string> */
    public static function values(FieldDefinition $field): array
    {
        return array_column(self::options($field), 'value');
    }
}
