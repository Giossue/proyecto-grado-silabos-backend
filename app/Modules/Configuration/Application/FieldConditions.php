<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

/**
 * «Mostrar solo si»: una parte del docente puede depender de otra parte Sí/No o de
 * selección de la misma plantilla (una sola condición, sin combinaciones). Mientras la
 * condición no se cumple, la parte no se pide, no es obligatoria y sale vacía en el
 * documento; su valor guardado no se borra.
 *
 * @phpstan-type Condition array{key: string, value: string}
 */
final class FieldConditions
{
    /** Tipos de parte que pueden servir de condición. */
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

    /** @return list<string> Valores que puede tomar una parte de condición. */
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

    /** Mensaje de error en español simple, o null si la condición es válida. */
    public static function error(string $templateId, string $ownKey, mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $condition = self::normalize($raw);
        if ($condition === null) {
            return 'Elija la parte y el valor de «Mostrar solo si».';
        }
        if ($condition['key'] === $ownKey) {
            return 'Una parte no puede depender de sí misma.';
        }
        $target = FieldDefinition::query()
            ->where('plantilla_id', $templateId)
            ->where('clave', $condition['key'])
            ->first();
        if ($target === null || ! in_array($target->tipo, self::CHOICE_TYPES, true)) {
            return 'La parte elegida en «Mostrar solo si» debe ser de Sí/No o de selección.';
        }
        if (! in_array($condition['value'], self::choiceValues($target), true)) {
            return 'El valor elegido no es una opción de esa parte.';
        }

        return null;
    }

    /** @param Condition $condition */
    public static function met(array $condition, mixed $value): bool
    {
        return self::text($value) === $condition['value'];
    }

    /**
     * Claves de las partes ocultas con los valores actuales. Una condición que apunta a
     * una parte que ya no existe o que dejó de ser de selección no oculta nada.
     *
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
