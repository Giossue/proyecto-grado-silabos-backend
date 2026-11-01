<?php

namespace App\Modules\Configuration\Domain\Blocks;

/**
 * Declara que un bloque llena sus filas con libros del catálogo de la biblioteca:
 * `list` es la lista del bloque y `map` dice qué dato del libro va a cada columna.
 */
final class BlockCatalog
{
    public const FIELDS = ['author', 'title', 'year', 'place', 'publisher', 'isbn', 'call_number'];

    /**
     * @param  array<string, array<string, mixed>>  $value
     * @return array{list: string, map: array<string, string>}|null
     */
    public static function define(mixed $raw, string $where, array $value): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (! is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), ['list', 'map']) !== []) {
            BlockDefinitionError::fail("{$where}: use «list» y «map».");
        }
        $list = $raw['list'] ?? null;
        $items = is_string($list) ? ($value[$list] ?? null) : null;
        if (($items['type'] ?? null) !== 'array' || ($items['items']['type'] ?? null) !== 'object') {
            BlockDefinitionError::fail("{$where}.list: debe ser una lista de filas del bloque.");
        }
        $map = $raw['map'] ?? null;
        if (! is_array($map) || array_is_list($map) || $map === []) {
            BlockDefinitionError::fail("{$where}.map: indique qué dato del libro va a cada columna.");
        }
        foreach ($map as $field => $column) {
            if (! in_array($field, self::FIELDS, true)) {
                BlockDefinitionError::fail("{$where}.map: «{$field}» no es un dato del libro (".implode(', ', self::FIELDS).').');
            }
            $schema = is_string($column) ? ($items['items']['properties'][$column] ?? null) : null;
            if (($schema['type'] ?? null) !== 'string' || isset($schema['oneOf'])) {
                BlockDefinitionError::fail("{$where}.map.{$field}: debe ser una columna de texto de la lista.");
            }
        }
        /** @var array<string, string> $map */
        return ['list' => $list, 'map' => $map];
    }
}
