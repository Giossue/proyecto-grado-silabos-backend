<?php

namespace App\Modules\Configuration\Domain\Blocks;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RepeatableTable
{
    public const TYPES = ['text', 'choice', 'number'];

    public const HEADER_STYLE = 'background:#C3D4EA;font-weight:bold';

    public const JSON_POINTER = 'Para cambiarla use Opciones avanzadas → Editar JSON.';

    private const KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $variables
     * @return array{design: array<string, mixed>|null, reason: string|null}
     */
    public static function read(string $kind, array $definition, array $variables): array
    {
        $reason = self::unsupported($definition);
        if ($reason !== null) {
            return ['design' => null, 'reason' => "{$reason} ".self::JSON_POINTER];
        }
        $design = self::extract($kind, $definition);
        try {
            $rebuilt = BlockDefinition::define($kind, self::build($design), $variables);
        } catch (ValidationException) {
            $rebuilt = null;
        }
        if ($rebuilt !== self::comparable($definition)) {
            return ['design' => null, 'reason' => 'El diseño usa piezas que este editor no maneja (celdas combinadas, totales, contadores, condiciones o filas extra). '.self::JSON_POINTER];
        }

        return ['design' => $design, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  list<string>  $variables
     */
    public static function locked(string $kind, ?array $stored, array $variables): ?string
    {
        if ($stored === null || self::read($kind, $stored, $variables)['design'] !== null) {
            return null;
        }

        return 'La tabla «'.($stored['label'] ?? $kind).'» no se puede cambiar desde el editor. '.self::JSON_POINTER;
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $changed
     * @param  list<string>  $variables
     */
    public static function guard(string $kind, ?array $stored, array $changed, array $variables): void
    {
        if ($stored !== null && self::comparable($stored) === self::comparable($changed)) {
            return;
        }
        $locked = self::locked($kind, $stored, $variables) ?? self::locked($kind, $changed, $variables);
        if ($locked !== null) {
            BlockDefinitionError::fail($locked);
        }
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public static function build(array $design): array
    {
        $design = self::withKeys($design);
        $list = $design['list'];
        $extras = is_array($design['extras'] ?? null) ? $design['extras'] : [];
        $columnErrors = is_array($extras['column_errors'] ?? null) ? $extras['column_errors'] : [];
        $properties = [];
        foreach ($design['columns'] as $index => $column) {
            $schema = self::schema($column, $index);
            $saved = $columnErrors[$column['key']] ?? null;
            if (is_array($saved) && self::canonical(is_array($saved['signature'] ?? null) ? $saved['signature'] : []) === self::signature($column) && is_string($saved['message'] ?? null)) {
                $schema['errorMessage'] = $saved['message'];
            }
            $properties[$column['key']] = $schema;
        }
        $array = [
            'type' => 'array',
            'minItems' => $design['min'],
            'maxItems' => $design['max'],
            'initial' => $design['initial'],
            'title' => $design['item'],
        ];
        $listError = is_array($extras['list_error'] ?? null) ? $extras['list_error'] : null;
        if ($listError !== null && self::canonical(is_array($listError['signature'] ?? null) ? $listError['signature'] : []) === self::canonical([$design['min'], $design['max'], $design['item']]) && is_string($listError['message'] ?? null)) {
            $array['errorMessage'] = $listError['message'];
        }
        $array['items'] = ['type' => 'object', 'properties' => $properties];

        return [
            'label' => $design['label'],
            'value' => [$list => $array],
            'rules' => self::rules($design, $extras),
            ...(is_array($extras['catalog'] ?? null) ? ['catalog' => $extras['catalog']] : []),
            'layout' => self::layout($design),
        ];
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  list<string>  $taken
     * @return array<string, mixed>
     */
    public static function withKeys(array $design, array $taken = []): array
    {
        $used = [];
        foreach ($design['columns'] as $column) {
            if (is_string($column['key'] ?? null) && $column['key'] !== '') {
                $used[] = $column['key'];
            }
        }
        foreach ($design['columns'] as $index => $column) {
            if (! is_string($column['key'] ?? null) || $column['key'] === '') {
                $key = self::key((string) ($column['title'] ?? ''), 'columna', $used);
                $used[] = $key;
                $design['columns'][$index]['key'] = $key;
            }
            if (($column['type'] ?? null) === 'choice') {
                $values = [];
                foreach (is_array($column['options'] ?? null) ? $column['options'] : [] as $position => $option) {
                    if (! is_string($option['value'] ?? null) || $option['value'] === '') {
                        $option['value'] = self::key((string) ($option['label'] ?? ''), 'opcion', $values);
                    }
                    $values[] = $option['value'];
                    $design['columns'][$index]['options'][$position] = $option;
                }
            }
        }
        if (! is_string($design['list'] ?? null) || $design['list'] === '') {
            $design['list'] = 'filas';
        }
        if (! is_string($design['kind'] ?? null) || $design['kind'] === '') {
            $design['kind'] = self::key('tabla '.(string) ($design['label'] ?? ''), 'tabla', $taken);
        }

        return $design;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function unsupported(array $definition): ?string
    {
        if (($definition['props'] ?? []) !== []) {
            return 'La tabla usa propiedades que se llenan en cada aparición.';
        }
        $value = $definition['value'] ?? [];
        if ($value === []) {
            return 'No tiene datos que llene el docente: es una tabla fija.';
        }
        $list = count($value) === 1 ? reset($value) : null;
        if (! is_array($list) || ($list['type'] ?? null) !== 'array' || ($list['items']['type'] ?? null) !== 'object') {
            return 'No es una sola lista de filas.';
        }
        if (isset($list['keep_if'])) {
            return 'La lista usa condiciones.';
        }
        foreach ($list['items']['properties'] ?? [] as $schema) {
            if (! in_array($schema['type'] ?? null, ['string', 'number'], true)) {
                return 'Tiene listas o grupos dentro de cada fila.';
            }
            if (isset($schema['keep_if'])) {
                return 'Una columna usa condiciones.';
            }
            if (isset($schema['minLength'])) {
                return 'Una columna usa un mínimo de caracteres.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function extract(string $kind, array $definition): array
    {
        $listKey = (string) array_key_first($definition['value']);
        $list = $definition['value'][$listKey];
        $widths = self::widths($definition['layout'] ?? [], $listKey);
        $rules = is_array($definition['rules'] ?? null) ? $definition['rules'] : [];
        $required = [];
        foreach ($rules as $rule) {
            $column = self::requiredColumn($rule, $listKey);
            if ($column !== null) {
                $required[$column] = true;
            }
        }
        $columns = [];
        $columnErrors = [];
        foreach ($list['items']['properties'] as $key => $schema) {
            $column = ['key' => $key, 'title' => $schema['title'] ?? $key];
            if (ValueSchema::isChoice($schema)) {
                $column += ['type' => 'choice', 'options' => array_map(fn (array $option): array => ['value' => $option['const'], 'label' => $option['title']], $schema['oneOf'])];
            } elseif ($schema['type'] === 'number') {
                $column += ['type' => 'number', 'maximum' => $schema['maximum'], 'decimals' => ValueSchema::digits($schema)[1]];
            } else {
                $column += ['type' => 'text', 'max_length' => $schema['maxLength'], 'multiline' => $schema['multiline'] ?? false];
            }
            $column += ['width' => $widths[$key] ?? null, 'required' => isset($required[$key])];
            if (isset($schema['errorMessage'])) {
                $columnErrors[$key] = ['message' => $schema['errorMessage'], 'signature' => self::signature($column)];
            }
            $columns[] = $column;
        }
        $extras = [
            'rules' => $rules,
            ...($columnErrors === [] ? [] : ['column_errors' => $columnErrors]),
            ...(isset($list['errorMessage']) ? ['list_error' => ['message' => $list['errorMessage'], 'signature' => [$list['minItems'], $list['maxItems'], $list['title'] ?? '']]] : []),
            ...(isset($definition['catalog']) ? ['catalog' => $definition['catalog']] : []),
        ];

        return [
            'kind' => $kind,
            'label' => $definition['label'],
            'list' => $listKey,
            'item' => $list['title'] ?? '',
            'min' => $list['minItems'],
            'max' => $list['maxItems'],
            'initial' => $list['initial'],
            'band' => self::band($definition['layout'] ?? []),
            'columns' => $columns,
            'extras' => $extras,
        ];
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<string, mixed>
     */
    private static function schema(array $column, int $index): array
    {
        $schema = ['type' => $column['type'] === 'number' ? 'number' : 'string', 'title' => $column['title']];

        return match ($column['type']) {
            'choice' => [...$schema, 'oneOf' => array_map(fn (array $option): array => ['const' => $option['value'], 'title' => $option['label']], $column['options'] ?? [])],
            'number' => [...$schema, 'maximum' => $column['maximum'] ?? 999.99, 'multipleOf' => self::multiple((int) ($column['decimals'] ?? 2))],
            'text' => [...$schema, 'maxLength' => $column['max_length'] ?? 1000, 'multiline' => (bool) ($column['multiline'] ?? false)],
            default => throw ValidationException::withMessages(["columns.{$index}.type" => 'Elija el tipo de la columna.']),
        };
    }

    private static function multiple(int $decimals): int|float
    {
        return $decimals <= 0 ? 1 : round(10 ** -$decimals, $decimals);
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<string, mixed>
     */
    private static function signature(array $column): array
    {
        return self::canonical(array_diff_key($column, array_flip(['width', 'required'])));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => match (true) {
            is_array($item) => self::canonical($item),
            is_int($item) || is_float($item) => (float) $item,
            default => $item,
        }, $value);
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $extras
     * @return list<array<string, mixed>>
     */
    private static function rules(array $design, array $extras): array
    {
        $list = $design['list'];
        $required = [];
        foreach ($design['columns'] as $column) {
            if ($column['required'] ?? false) {
                $required[$column['key']] = $column;
            }
        }
        $rules = [];
        $covered = [];
        foreach (is_array($extras['rules'] ?? null) ? $extras['rules'] : [] as $rule) {
            $column = is_array($rule) ? self::requiredColumn($rule, $list) : null;
            if ($column !== null) {
                if (isset($required[$column]) && ! isset($covered[$column])) {
                    $rules[] = $rule;
                    $covered[$column] = true;
                }

                continue;
            }
            $rules[] = $rule;
        }
        $codes = array_map(fn (array $rule): mixed => $rule['code'] ?? null, $rules);
        foreach ($required as $key => $column) {
            if (isset($covered[$key])) {
                continue;
            }
            $code = self::key("{$key} obligatoria", 'columna_obligatoria', array_filter($codes, 'is_string'));
            $codes[] = $code;
            $rules[] = [
                'code' => $code,
                'on' => 'submit',
                'message' => "Llene «{$column['title']}» en cada ".($design['item'] !== '' ? $design['item'] : 'fila').': {detail}.',
                'checks' => [['label' => mb_substr($column['title'], 0, 60), 'filled' => "{$list}.*.{$key}", 'equals' => ['count' => $list]]],
            ];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function requiredColumn(array $rule, string $list): ?string
    {
        $checks = $rule['checks'] ?? null;
        if (($rule['on'] ?? null) !== 'submit' || ! is_array($checks) || count($checks) !== 1) {
            return null;
        }
        $check = $checks[0];
        $extra = array_diff(array_keys($check), ['filled', 'equals', 'label']);
        if ($extra !== [] || ($check['equals'] ?? null) !== ['count' => $list] || ! is_string($check['filled'] ?? null)) {
            return null;
        }

        return preg_match('/^'.preg_quote($list, '/').'\.\*\.([a-z][a-z0-9_]*)$/', $check['filled'], $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<string>
     */
    private static function layout(array $design): array
    {
        $headers = '';
        $cells = '';
        foreach ($design['columns'] as $column) {
            $width = is_int($column['width'] ?? null) ? ' data-width="'.$column['width'].'"' : '';
            $headers .= '<th'.$width.' style="'.self::HEADER_STYLE.'">'.htmlspecialchars((string) $column['title'], ENT_QUOTES | ENT_HTML5).'</th>';
            $cells .= '<td'.$width.'><span data-slot=\'{"value":"'.$column['key'].'"}\'></span></td>';
        }
        $band = is_string($design['band'] ?? null) && $design['band'] !== '' ? ' data-band="'.htmlspecialchars($design['band'], ENT_QUOTES).'"' : '';

        return [
            '<table>',
            "<tr>{$headers}</tr>",
            '<tr data-repeat=\'{"value":"'.$design['list'].'"}\''.$band.">{$cells}</tr>",
            '</table>',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $layout
     * @return array<string, int>
     */
    private static function widths(array $layout, string $list): array
    {
        $rows = $layout[0]['content'] ?? [];
        $widths = [];
        foreach ($rows as $row) {
            if (($row['attrs']['repeat']['value'] ?? null) !== $list) {
                continue;
            }
            foreach ($row['content'] ?? [] as $cell) {
                $slot = $cell['content'][0]['content'][0]['attrs']['value'] ?? null;
                $width = $cell['attrs']['colwidth'][0] ?? null;
                if (is_string($slot) && is_int($width)) {
                    $widths[$slot] = $width;
                }
            }
        }

        return $widths;
    }

    /**
     * @param  list<array<string, mixed>>  $layout
     */
    private static function band(array $layout): ?string
    {
        foreach ($layout[0]['content'] ?? [] as $row) {
            if (isset($row['attrs']['repeat'])) {
                $band = $row['attrs']['band'] ?? null;

                return is_string($band) ? $band : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function comparable(array $definition): array
    {
        return [
            'label' => $definition['label'] ?? null,
            'props' => $definition['props'] ?? [],
            'value' => $definition['value'] ?? [],
            'rules' => $definition['rules'] ?? [],
            'layout' => $definition['layout'] ?? [],
            ...(isset($definition['catalog']) ? ['catalog' => $definition['catalog']] : []),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $used
     */
    public static function key(string $name, string $fallback, array $used): string
    {
        $base = Str::slug($name, '_');
        $base = substr((string) preg_replace('/^[0-9_]+/', '', $base), 0, 36);
        if ($base === '' || preg_match(self::KEY, $base) !== 1) {
            $base = $fallback;
        }
        $key = $base;
        for ($n = 2; in_array($key, $used, true); $n++) {
            $key = "{$base}_{$n}";
        }

        return $key;
    }
}
