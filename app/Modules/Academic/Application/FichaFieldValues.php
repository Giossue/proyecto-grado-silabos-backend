<?php

namespace App\Modules\Academic\Application;

use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use Illuminate\Support\Str;

final class FichaFieldValues
{
    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array{key: string, label: string, columns: list<string>}>  $fields
     * @return array{coverage: array{found: int, total: int}, missing: list<array{key: string, label: string, reason: string}>}
     */
    public function coverage(array $entry, array $fields): array
    {
        $values = array_intersect_key($entry['values'] ?? [], array_flip(array_column($fields, 'key')));
        $stored = array_column($entry['missing'] ?? [], null, 'key');
        $missing = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field['key'], $values)) {
                $missing[] = ['key' => $field['key'], 'label' => $field['label'], 'reason' => $stored[$field['key']]['reason'] ?? (($entry['state'] ?? '') === 'fallida' ? 'servicio_no_disponible' : 'no_encontrado')];
            }
        }

        return ['coverage' => ['found' => count($values), 'total' => count($fields)], 'missing' => $missing];
    }

    /** @return array{key: string, label: string, columns: list<string>} */
    public function schema(FieldDefinition $field): array
    {
        return ['key' => $field->clave, 'label' => $field->displayName(), 'columns' => $this->columns($field->contenido)];
    }

    /** @return array{key: string, label: string, columns: list<string>, section: array{key: string, label: string}, format: string, template: array<string, mixed>, components: array<string, mixed>} */
    public function instructions(FieldDefinition $field): array
    {
        $schema = $this->schema($field);
        $block = $field->block;
        $components = [];
        $catalog = $block?->template->definition()->toArray()['blocks'] ?? [];
        $remaining = 12000;
        $template = $this->structure($field->contenido, $catalog, $components, $remaining);

        return [...$schema,
            'section' => ['key' => $block?->section->clave ?? '', 'label' => $block?->section->titulo ?? ''],
            'format' => $schema['columns'] === [] ? 'text_or_list' : 'table',
            'template' => $template,
            'components' => $components,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     */
    private function structure(array $node, array $catalog, array &$components, int &$remaining): array
    {
        $result = ['type' => $node['type'] ?? 'paragraph'];
        if (is_string($node['text'] ?? null)) {
            $result['text'] = mb_substr($node['text'], 0, max(0, $remaining));
            $remaining -= mb_strlen($result['text']);
        }
        $attrs = array_intersect_key($node['attrs'] ?? [], array_flip(['rowspan', 'colspan', 'level', 'kind', 'props', 'value']));
        if ($attrs !== []) {
            $result['attrs'] = $attrs;
        }
        $kind = $attrs['kind'] ?? null;
        if (is_string($kind) && is_array($catalog[$kind] ?? null) && ! isset($components[$kind])) {
            $definition = $catalog[$kind];
            $components[$kind] = array_intersect_key($definition, array_flip(['label', 'props', 'value', 'rules']));
            $layout = $definition['layout'] ?? [];
            $components[$kind]['guidance'] = mb_substr(trim(strip_tags(is_array($layout) ? implode("\n", $layout) : (string) $layout)), 0, 4000);
        }
        foreach (array_slice($node['content'] ?? [], 0, 200) as $child) {
            if ($remaining <= 0) {
                $result['truncated'] = true;
                break;
            }
            if (is_array($child)) {
                $result['content'][] = $this->structure($child, $catalog, $components, $remaining);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function columns(array $document): array
    {
        if (($document['type'] ?? '') !== 'table') {
            foreach ($document['content'] ?? [] as $node) {
                $columns = $this->columns($node);
                if ($columns !== []) {
                    return $columns;
                }
            }

            return [];
        }
        $grid = [];
        foreach ($document['content'] ?? [] as $rowIndex => $row) {
            $column = 0;
            $headers = array_filter($row['content'] ?? [], fn (array $cell): bool => ($cell['type'] ?? '') === 'tableHeader');
            if ($headers === []) {
                break;
            }
            foreach ($row['content'] as $cell) {
                while (isset($grid[$rowIndex][$column])) {
                    $column++;
                }
                $text = trim(FreeText::plainText(['type' => 'doc', 'content' => $cell['content'] ?? []]));
                $colspan = min(12, max(1, (int) ($cell['attrs']['colspan'] ?? 1)));
                $rowspan = min(6, max(1, (int) ($cell['attrs']['rowspan'] ?? 1)));
                for ($r = $rowIndex; $r < $rowIndex + $rowspan; $r++) {
                    for ($c = $column; $c < min(12, $column + $colspan); $c++) {
                        $grid[$r][$c] = $text;
                    }
                }
                $column += $colspan;
            }
        }
        $labels = [];
        foreach ($grid as $row) {
            foreach ($row as $column => $text) {
                if ($text !== '' && ! in_array($text, $labels[$column] ?? [], true)) {
                    $labels[$column][] = $text;
                }
            }
        }
        ksort($labels);

        return array_values(array_map(fn (array $parts): string => implode(' / ', $parts), $labels));
    }

    /**
     * @param  list<string>  $columns
     * @return string|list<string>|array{columns: list<string>, rows: list<list<string>>}|null
     */
    public function clean(mixed $value, array $columns): string|array|null
    {
        if (is_array($value) && isset($value['rows']) && is_array($value['rows'])) {
            if ($columns === []) {
                return null;
            }
            $rows = [];
            foreach (array_slice($value['rows'], 0, 40) as $row) {
                if (! is_array($row) || ! array_is_list($row) || count($row) !== count($columns)
                    || array_filter($row, fn (mixed $cell): bool => ! is_string($cell)) !== []) {
                    return null;
                }
                $cells = array_map(fn (string $cell): string => Str::limit(trim($cell), 1500, ''), $row);
                if (array_filter($cells, fn (string $cell): bool => $cell !== '') !== []) {
                    $rows[] = $cells;
                }
            }

            return $rows === [] ? null : ['columns' => $columns, 'rows' => $rows];
        }
        if ($columns !== []) {
            return null;
        }
        if (is_string($value)) {
            $text = Str::limit(trim($value), 12000, '');

            return $text === '' ? null : $text;
        }
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn (mixed $item): bool => ! is_string($item)) !== []) {
            return null;
        }
        $items = array_values(array_filter(array_map(fn (string $item): string => Str::limit(trim($item), 3000, ''), array_slice($value, 0, 60)), fn (string $item): bool => $item !== ''));

        return $items === [] ? null : $items;
    }

    /**
     * @param  array<string, mixed>|null  $template
     * @return array<string, mixed>
     */
    public function document(mixed $value, ?array $template = null): array
    {
        if (is_array($value) && isset($value['columns'], $value['rows'])) {
            $columns = [];
            foreach (array_values($value['columns']) as $index => $label) {
                $columns[] = ['key' => (string) $index, 'label' => $label];
            }

            $document = FreeText::fromRows($columns, $value['rows']);
            $filled = false;

            return $template === null ? $document : $this->fillTable($template, array_values(array_slice($document['content'][0]['content'], 1)), $filled);
        }

        return is_array($value)
            ? FreeText::fromRows([['key' => 't', 'label' => '']], array_values(array_map(fn (string $item): array => ['t' => $item], $value)))
            : FreeText::fromText((string) $value);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function fillTable(array $node, array $rows, bool &$filled): array
    {
        if ($filled) {
            return $node;
        }
        if (($node['type'] ?? '') === 'table') {
            $headers = array_values(array_filter($node['content'] ?? [], fn (array $row): bool => ($row['content'] ?? []) !== []
                && array_all($row['content'], fn (array $cell): bool => ($cell['type'] ?? '') === 'tableHeader')));
            $node['content'] = [...$headers, ...$rows];
            $filled = true;
        } elseif (isset($node['content'])) {
            foreach ($node['content'] as $index => $child) {
                $node['content'][$index] = $this->fillTable($child, $rows, $filled);
            }
        }

        return $node;
    }
}
