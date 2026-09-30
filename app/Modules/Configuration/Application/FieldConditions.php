<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

/**
 * «Mostrar solo si»: un campo del docente puede depender de otro campo Sí/No o de
 * selección de la misma plantilla (una sola condición, sin combinaciones). Mientras la
 * condición no se cumple, el campo no se pide, no es obligatorio y sale vacío en el
 * documento; su valor guardado no se borra.
 *
 * @phpstan-type Condition array{key: string, value: string}
 */
final class FieldConditions
{
    public const CHOICE_TYPES = ['seleccion_unica', 'booleano'];

    /** @return Condition|null */
    public static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw) || ! is_string($raw['key'] ?? null) || ! is_scalar($raw['value'] ?? null)) {
            return null;
        }
        $key = trim($raw['key']);
        $value = self::text($raw['value']);

        return $key === '' || $value === '' ? null : ['key' => $key, 'value' => $value];
    }

    /** @return list<string> Valores que puede tomar un campo de condición. */
    public static function choiceValues(FieldDefinition $field): array
    {
        if ($field->tipo === 'booleano') {
            return ['true', 'false'];
        }
        $values = [];
        foreach ($field->opciones ?? [] as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : $option;
            if (is_string($value) || is_int($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    public static function error(string $templateId, string $ownKey, mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $condition = self::normalize($raw);
        if ($condition === null) {
            return 'Elija el campo y el valor de «Mostrar solo si».';
        }
        if ($condition['key'] === $ownKey) {
            return 'Un campo no puede depender de sí mismo.';
        }
        $target = FieldDefinition::query()
            ->where('plantilla_id', $templateId)
            ->where('clave', $condition['key'])
            ->first();
        if ($target === null || ! in_array($target->tipo, self::CHOICE_TYPES, true)) {
            return 'El campo elegido en «Mostrar solo si» debe ser de Sí/No o de selección.';
        }
        if (! in_array($condition['value'], self::choiceValues($target), true)) {
            return 'El valor elegido no es una opción de ese campo.';
        }

        return null;
    }

    /** @param Condition $condition */
    public static function met(array $condition, mixed $value): bool
    {
        return self::text($value) === $condition['value'];
    }

    /**
     * @param  iterable<FieldDefinition>  $fields  Todas las definiciones de la plantilla.
     * @param  array<string, mixed>  $valuesByKey
     * @return list<string>
     */
    public static function hiddenKeys(iterable $fields, array $valuesByKey): array
    {
        $existing = [];
        $conditions = [];
        foreach ($fields as $field) {
            $existing[$field->clave] = in_array($field->tipo, self::CHOICE_TYPES, true);
            $condition = self::normalize($field->mostrar_si);
            if ($condition !== null) {
                $conditions[$field->clave] = $condition;
            }
        }
        $hidden = [];
        foreach ($conditions as $key => $condition) {
            if (($existing[$condition['key']] ?? false) && ! self::met($condition, $valuesByKey[$condition['key']] ?? null)) {
                $hidden[] = $key;
            }
        }

        return $hidden;
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
