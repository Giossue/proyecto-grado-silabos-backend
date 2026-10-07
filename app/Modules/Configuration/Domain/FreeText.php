<?php

namespace App\Modules\Configuration\Domain;

use Illuminate\Validation\ValidationException;

final class FreeText
{
    private const MAX_NODES = 5000;

    private const MAX_DEPTH = 12;

    private const MAX_CHARACTERS = 50000;

    private const CHILDREN = [
        'doc' => ['paragraph', 'bulletList', 'orderedList', 'table'],
        'paragraph' => ['text', 'hardBreak'],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => ['paragraph', 'bulletList', 'orderedList'],
        'table' => ['tableRow'],
        'tableRow' => ['tableCell', 'tableHeader'],
        'tableCell' => ['paragraph', 'bulletList', 'orderedList'],
        'tableHeader' => ['paragraph', 'bulletList', 'orderedList'],
    ];

    public static function isDocument(mixed $value): bool
    {
        return is_array($value) && ($value['type'] ?? null) === 'doc';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function normalize(mixed $value, string $attribute = 'value'): array
    {
        if (! self::isDocument($value)) {
            self::fail($attribute, 'El texto libre no tiene un formato válido.');
        }
        $count = 0;
        $characters = 0;
        /** @var array<string, mixed> $value */
        $document = self::node($value, null, 0, $count, $characters, $attribute);
        if ($characters > self::MAX_CHARACTERS) {
            self::fail($attribute, 'El texto libre supera los 50 000 caracteres.');
        }

        return $document;
    }

    /** @param array<string, mixed> $document */
    public static function isEmpty(array $document): bool
    {
        return trim(self::plainText($document)) === '';
    }

    /** @param  array<string, mixed>  $node */
    public static function plainText(array $node): string
    {
        $type = $node['type'] ?? null;
        if ($type === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        $children = array_map(
            fn (mixed $child): string => is_array($child) ? self::plainText($child) : '',
            is_array($node['content'] ?? null) ? $node['content'] : [],
        );
        if ($type === 'tableRow') {
            return implode(' | ', $children);
        }
        if ($type === 'listItem') {
            return '• '.implode("\n", $children);
        }

        return implode($type === 'paragraph' ? '' : "\n", $children);
    }

    /** @return array<string, mixed> */
    public static function fromText(string $text): array
    {
        $paragraphs = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $paragraphs[] = trim($line) === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
        }

        return ['type' => 'doc', 'content' => $paragraphs === [] ? [['type' => 'paragraph']] : $paragraphs];
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function fromRows(array $columns, array $rows, bool $numbered = false): array
    {
        $cell = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $paragraph = fn (string $text): array => $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $rows = array_values(array_filter($rows, fn (array $row): bool => array_filter(
            array_map(fn (array $column): string => $cell($row[$column['key']] ?? null), $columns),
            fn (string $text): bool => $text !== '',
        ) !== []));
        if ($rows === []) {
            return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
        }
        if (count($columns) === 1) {
            return ['type' => 'doc', 'content' => [['type' => $numbered ? 'orderedList' : 'bulletList', 'content' => array_map(
                fn (array $row): array => ['type' => 'listItem', 'content' => [$paragraph($cell($row[$columns[0]['key']] ?? null))]],
                $rows,
            )]]];
        }
        $tableRow = fn (array $cells, string $type): array => ['type' => 'tableRow', 'content' => array_map(
            fn (string $text): array => ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [$paragraph($text)]],
            $cells,
        )];

        return ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
            $tableRow(array_map(fn (array $column): string => $column['label'], $columns), 'tableHeader'),
            ...array_map(fn (array $row): array => $tableRow(array_map(fn (array $column): string => $cell($row[$column['key']] ?? null), $columns), 'tableCell'), $rows),
        ]]]];
    }

    /**
     * Tabla de filas y columnas fijas: encabezado con las columnas y la etiqueta de cada fila.
     *
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array{key: string, label: string}>  $rows
     * @param  array<string, mixed>  $values  mapa fila → columna → texto
     * @return array<string, mixed>
     */
    public static function fixedTable(array $columns, array $rows, array $values): array
    {
        $paragraph = fn (string $text): array => $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $cell = fn (string $text, string $type): array => ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [$paragraph($text)]];
        $header = ['type' => 'tableRow', 'content' => [
            $cell('', 'tableHeader'),
            ...array_map(fn (array $column): array => $cell($column['label'], 'tableHeader'), $columns),
        ]];
        $body = array_map(function (array $row) use ($columns, $values, $cell): array {
            $cells = is_array($values[$row['key']] ?? null) ? $values[$row['key']] : [];

            return ['type' => 'tableRow', 'content' => [
                $cell($row['label'], 'tableHeader'),
                ...array_map(fn (array $column): array => $cell(is_scalar($cells[$column['key']] ?? null) ? trim((string) $cells[$column['key']]) : '', 'tableCell'), $columns),
            ]];
        }, $rows);

        return ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [$header, ...$body]]]];
    }

    /** @return list<array<string, mixed>> */
    public static function blocks(mixed $value): array
    {
        if (! self::isDocument($value)) {
            return [];
        }
        $content = $value['content'] ?? [];

        return is_array($content) ? array_values(array_filter($content, is_array(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function node(array $raw, ?string $parent, int $depth, int &$count, int &$characters, string $attribute): array
    {
        $type = $raw['type'] ?? null;
        $allowed = $parent === null ? ['doc'] : self::CHILDREN[$parent];
        if (! is_string($type) || ! in_array($type, $allowed, true)) {
            self::fail($attribute, 'El texto libre contiene un elemento no permitido.');
        }
        if (++$count > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            self::fail($attribute, 'El texto libre es demasiado extenso o anidado.');
        }
        if ($type === 'text') {
            $text = $raw['text'] ?? null;
            if (! is_string($text) || $text === '') {
                self::fail($attribute, 'El texto libre contiene un texto vacío.');
            }
            $characters += mb_strlen($text);
            $marks = [];
            foreach (is_array($raw['marks'] ?? null) ? $raw['marks'] : [] as $mark) {
                $name = is_array($mark) ? ($mark['type'] ?? null) : null;
                if (in_array($name, ['bold', 'italic'], true)) {
                    $marks[] = ['type' => $name];
                }
            }

            return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
        }
        if ($type === 'hardBreak') {
            return ['type' => 'hardBreak'];
        }
        $node = ['type' => $type];
        if (in_array($type, ['tableCell', 'tableHeader'], true)) {
            $attrs = is_array($raw['attrs'] ?? null) ? $raw['attrs'] : [];
            $node['attrs'] = [
                'colspan' => self::span($attrs['colspan'] ?? 1, $attribute),
                'rowspan' => self::span($attrs['rowspan'] ?? 1, $attribute),
            ];
        }
        $content = [];
        foreach (is_array($raw['content'] ?? null) ? $raw['content'] : [] as $child) {
            if (! is_array($child)) {
                self::fail($attribute, 'El texto libre contiene un elemento no permitido.');
            }
            $content[] = self::node($child, $type, $depth + 1, $count, $characters, $attribute);
        }
        if ($content === [] && $type !== 'paragraph') {
            if ($type === 'doc') {
                return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
            }
            self::fail($attribute, 'El texto libre contiene una lista o tabla vacía.');
        }
        if ($content !== []) {
            $node['content'] = $content;
        }

        return $node;
    }

    private static function span(mixed $value, string $attribute): int
    {
        if (! is_int($value) || $value < 1 || $value > 20) {
            self::fail($attribute, 'Una celda del texto libre no es válida.');
        }

        return $value;
    }

    private static function fail(string $attribute, string $message): never
    {
        throw ValidationException::withMessages([$attribute => $message]);
    }
}
