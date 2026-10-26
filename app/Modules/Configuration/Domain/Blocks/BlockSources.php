<?php

namespace App\Modules\Configuration\Domain\Blocks;

use App\Modules\Configuration\Domain\FreeText;
use Illuminate\Support\Str;

/**
 * Datos `from` de los bloques: se copian de la tabla de un campo de Carrera.
 * La fila es la posición del elemento de la lista que contiene el dato.
 */
final class BlockSources
{
    /**
     * Filas de datos (sin encabezados) de la primera tabla del documento.
     *
     * @param  array<mixed>  $document  documento Tiptap guardado
     * @return list<list<string>>
     */
    public static function rows(array $document): array
    {
        $table = self::table($document);
        $rows = [];
        foreach ($table['content'] ?? [] as $row) {
            $cells = is_array($row['content'] ?? null) ? $row['content'] : [];
            if (array_filter($cells, fn (mixed $cell): bool => ($cell['type'] ?? null) === 'tableHeader') !== []) {
                continue;
            }
            $rows[] = array_values(array_map(fn (array $cell): string => trim(FreeText::plainText(['type' => 'doc', 'content' => $cell['content'] ?? []])), $cells));
        }

        return $rows;
    }

    /**
     * Copia en los bloques del documento los datos que vienen de otros campos.
     *
     * @param  array<mixed>  $document  documento Tiptap guardado
     * @param  array<string, array<string, mixed>>  $blocks  definiciones por tipo
     * @param  array<string, list<list<string>>>  $tables  filas por clave de campo
     * @return array<mixed>
     */
    public static function fill(array $document, array $blocks, array $tables): array
    {
        if (($document['type'] ?? null) === 'documentBlock') {
            $definition = $blocks[$document['attrs']['kind'] ?? ''] ?? null;
            if ($definition !== null) {
                $value = is_array($document['attrs']['value'] ?? null) ? $document['attrs']['value'] : [];
                $document['attrs']['value'] = self::object($definition['value'], $value, $tables, 1);
            }

            return $document;
        }
        if (is_array($document['content'] ?? null)) {
            $document['content'] = array_map(fn (mixed $node): mixed => is_array($node) ? self::fill($node, $blocks, $tables) : $node, $document['content']);
        }

        return $document;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $value
     * @param  array<string, list<list<string>>>  $tables
     * @return array<string, mixed>
     */
    private static function object(array $fields, array $value, array $tables, int $row): array
    {
        foreach ($fields as $key => $schema) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            if (isset($schema['from'])) {
                $text = $tables[$schema['from']['field']][$row - 1][$schema['from']['column'] - 1] ?? '';
                $value[$key] = Str::limit($text, $schema['max'], '');
            } elseif ($schema['type'] === 'object' && is_array($value[$key])) {
                $value[$key] = self::object($schema['fields'], $value[$key], $tables, $row);
            } elseif ($schema['type'] === 'list' && is_array($value[$key]) && ($schema['item']['type'] ?? null) === 'object') {
                foreach ($value[$key] as $index => $item) {
                    $value[$key][$index] = is_array($item) ? self::object($schema['item']['fields'], $item, $tables, $index + 1) : $item;
                }
            }
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>|null
     */
    private static function table(array $node): ?array
    {
        if (($node['type'] ?? null) === 'table') {
            return $node;
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $found = is_array($child) ? self::table($child) : null;
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
