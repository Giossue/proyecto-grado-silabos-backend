<?php

namespace App\Modules\Configuration\Domain;

final class DocumentBlock
{
    public const SI_NO = 'si_no';

    public const REPETIBLE = 'repetible';

    public const KINDS = [self::SI_NO, self::REPETIBLE];

    private const MAX_COLUMNS = 8;

    private const MAX_ROWS = 100;

    private const MAX_CELL = 1000;

    /**
     * @param  array<string, mixed>  $attrs
     * @return array{kind: string, props: array<string, mixed>, value: array<string, mixed>}
     */
    public static function normalize(array $attrs): array
    {
        $kind = $attrs['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            TemplateDocument::fail('El bloque no es de un tipo permitido.');
        }
        $props = self::props($kind, is_array($attrs['props'] ?? null) ? $attrs['props'] : []);

        return ['kind' => $kind, 'props' => $props, 'value' => self::value($kind, $props, $attrs['value'] ?? null)];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    public static function expand(array $node): array
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $kind = (string) ($attrs['kind'] ?? '');
        $props = is_array($attrs['props'] ?? null) ? $attrs['props'] : [];
        $value = is_array($attrs['value'] ?? null) ? $attrs['value'] : [];
        $columns = self::columnsOf($props);
        $rows = self::rowsOf($value);
        $nodes = [];
        if ($kind === self::SI_NO) {
            $answer = $value['answer'] ?? null;
            $nodes[] = self::paragraph((string) ($props['question'] ?? '').' '.match ($answer) {
                'si' => 'Sí',
                'no' => 'No',
                default => '',
            }, true);
            if ($answer === 'si' && $columns !== []) {
                $nodes[] = self::table($columns, $rows);
            }
        } elseif ($kind === self::REPETIBLE) {
            $nodes[] = self::table($columns, $rows);
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function plainText(array $attrs): string
    {
        $props = is_array($attrs['props'] ?? null) ? $attrs['props'] : [];
        $value = is_array($attrs['value'] ?? null) ? $attrs['value'] : [];
        $kind = (string) ($attrs['kind'] ?? '');
        $lines = [];
        if ($kind === self::SI_NO) {
            $answer = $value['answer'] ?? null;
            if ($answer !== 'si' && $answer !== 'no') {
                return '';
            }
            $lines[] = trim((string) ($props['question'] ?? '').' '.($answer === 'si' ? 'Sí' : 'No'));
            if ($answer === 'no') {
                return $lines[0];
            }
        }
        foreach (self::rowsOf($value) as $row) {
            $cells = array_filter(array_map(fn (string $cell): string => trim($cell), array_values($row)), fn (string $cell): bool => $cell !== '');
            if ($cells !== []) {
                $lines[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $document
     */
    public static function sameStructure(array $template, array $document): bool
    {
        return self::skeleton($template) === self::skeleton($document);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{kind: mixed, props: mixed}>
     */
    private static function skeleton(array $document): array
    {
        $blocks = [];
        foreach (is_array($document['content'] ?? null) ? $document['content'] : [] as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'block') {
                $blocks[] = ['kind' => $node['attrs']['kind'] ?? null, 'props' => $node['attrs']['props'] ?? null];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function props(string $kind, array $props): array
    {
        $clean = [];
        if ($kind === self::SI_NO) {
            $question = trim((string) ($props['question'] ?? ''));
            if ($question === '' || mb_strlen($question) > 300) {
                TemplateDocument::fail('La pregunta del bloque Sí/No es obligatoria y admite hasta 300 caracteres.');
            }
            $clean['question'] = $question;
        } else {
            $label = trim((string) ($props['label'] ?? ''));
            if (mb_strlen($label) > 120) {
                TemplateDocument::fail('El título de la lista repetible admite hasta 120 caracteres.');
            }
            $clean['label'] = $label;
        }
        $columns = [];
        $keys = [];
        foreach (is_array($props['columns'] ?? null) ? $props['columns'] : [] as $column) {
            $key = is_array($column) ? ($column['key'] ?? null) : null;
            $label = is_array($column) ? trim((string) ($column['label'] ?? '')) : '';
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key) !== 1 || in_array($key, $keys, true) || $label === '' || mb_strlen($label) > 120) {
                TemplateDocument::fail('Las columnas del bloque necesitan una clave única y un nombre de hasta 120 caracteres.');
            }
            $keys[] = $key;
            $columns[] = ['key' => $key, 'label' => $label];
        }
        if (count($columns) > self::MAX_COLUMNS || ($kind === self::REPETIBLE && $columns === [])) {
            TemplateDocument::fail($kind === self::REPETIBLE ? 'La lista repetible necesita entre 1 y 8 columnas.' : 'El bloque admite hasta 8 columnas.');
        }
        $clean['columns'] = $columns;

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function value(string $kind, array $props, mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $value = [];
        if ($kind === self::SI_NO) {
            $answer = $raw['answer'] ?? null;
            if ($answer !== null && ! in_array($answer, ['si', 'no'], true)) {
                TemplateDocument::fail('La respuesta del bloque Sí/No no es válida.');
            }
            $value['answer'] = $answer;
        }
        $keys = array_column(self::columnsOf($props), 'key');
        $rows = [];
        $input = array_is_list($raw['rows'] ?? []) && is_array($raw['rows'] ?? null) ? $raw['rows'] : [];
        if (count($input) > self::MAX_ROWS) {
            TemplateDocument::fail('El bloque admite hasta 100 filas.');
        }
        foreach ($input as $row) {
            $row = is_array($row) ? $row : [];
            $clean = [];
            foreach ($keys as $key) {
                $cell = $row[$key] ?? '';
                if (! is_string($cell) || mb_strlen($cell) > self::MAX_CELL) {
                    TemplateDocument::fail('Una celda del bloque supera los 1000 caracteres.');
                }
                $clean[$key] = $cell;
            }
            $rows[] = $clean;
        }
        if ($kind === self::SI_NO && ($value['answer'] ?? null) !== 'si') {
            $rows = [];
        }
        $value['rows'] = $rows;

        return $value;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<array{key: string, label: string}>
     */
    private static function columnsOf(array $props): array
    {
        $columns = [];
        foreach (is_array($props['columns'] ?? null) ? $props['columns'] : [] as $column) {
            if (is_array($column) && is_string($column['key'] ?? null) && is_string($column['label'] ?? null)) {
                $columns[] = ['key' => $column['key'], 'label' => $column['label']];
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<array<string, string>>
     */
    private static function rowsOf(array $value): array
    {
        $rows = [];
        foreach (is_array($value['rows'] ?? null) ? $value['rows'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = array_map(fn (mixed $cell): string => is_string($cell) ? $cell : '', $row);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $text, bool $bold = false): array
    {
        $text = trim($text);

        return $text === '' ? ['type' => 'paragraph'] : [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text] + ($bold ? ['marks' => [['type' => 'bold']]] : [])],
        ];
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, string>>  $rows
     * @return array<string, mixed>
     */
    private static function table(array $columns, array $rows): array
    {
        $cell = fn (string $type, string $text): array => [
            'type' => $type,
            'attrs' => ['colspan' => 1, 'rowspan' => 1],
            'content' => [self::paragraph($text)],
        ];
        $content = [['type' => 'tableRow', 'content' => array_map(fn (array $column): array => $cell('tableHeader', $column['label']), $columns)]];
        foreach ($rows === [] ? [[]] : $rows as $row) {
            $content[] = ['type' => 'tableRow', 'content' => array_map(fn (array $column): array => $cell('tableCell', (string) ($row[$column['key']] ?? '')), $columns)];
        }

        return ['type' => 'table', 'content' => $content];
    }
}
