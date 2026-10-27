<?php

namespace App\Modules\Configuration\Domain\Blocks;

use App\Modules\Configuration\Domain\TemplateDocument;

final class ValueSchema
{
    public const TYPES = ['text', 'number', 'choice', 'object', 'list'];

    private const KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function define(mixed $fields, string $where, bool $props = false): array
    {
        if ($fields === null) {
            return [];
        }
        if (! is_array($fields) || array_is_list($fields) && $fields !== []) {
            BlockDefinitionError::fail("{$where}: debe ser un objeto con un esquema por clave.");
        }
        $clean = [];
        foreach ($fields as $key => $schema) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                BlockDefinitionError::fail("{$where}: la clave «{$key}» debe usar minúsculas, números y _.");
            }
            $clean[$key] = self::schema($schema, "{$where}.{$key}", $props);
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(mixed $schema, string $where, bool $props): array
    {
        if (! is_array($schema) || ! in_array($schema['type'] ?? null, self::TYPES, true)) {
            BlockDefinitionError::fail("{$where}: «type» debe ser uno de ".implode(', ', self::TYPES).'.');
        }
        $type = $schema['type'];
        if ($props && $type !== 'text') {
            BlockDefinitionError::fail("{$where}: las propiedades solo admiten «text».");
        }
        $clean = ['type' => $type];
        foreach (['label', 'error'] as $text) {
            if (isset($schema[$text])) {
                $clean[$text] = self::string($schema[$text], "{$where}.{$text}", 300);
            }
        }
        if (isset($schema['keep_if'])) {
            $clean['keep_if'] = Condition::define($schema['keep_if'], "{$where}.keep_if");
        }
        if (isset($schema['required'])) {
            $clean['required'] = self::boolean($schema['required'], "{$where}.required");
        }
        match ($type) {
            'text' => $clean += [
                'max' => self::integer($schema['max'] ?? 1000, "{$where}.max", 1, 20000),
                'multiline' => self::boolean($schema['multiline'] ?? false, "{$where}.multiline"),
            ],
            'number' => $clean += [
                'digits' => self::integer($schema['digits'] ?? 3, "{$where}.digits", 1, 9),
                'decimals' => self::integer($schema['decimals'] ?? 2, "{$where}.decimals", 0, 6),
            ],
            'choice' => $clean['options'] = self::options($schema['options'] ?? null, "{$where}.options"),
            'object' => $clean['fields'] = self::define($schema['fields'] ?? null, "{$where}.fields"),
            'list' => $clean += self::listSchema($schema, $where),
        };

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function listSchema(array $schema, string $where): array
    {
        $min = self::integer($schema['min'] ?? 0, "{$where}.min", 0, 100);
        $max = self::integer($schema['max'] ?? 100, "{$where}.max", max(1, $min), 100);
        $item = $schema['item'] ?? null;
        if (! is_array($item)) {
            BlockDefinitionError::fail("{$where}.item: falta el esquema de cada elemento.");
        }

        return [
            'min' => $min,
            'max' => $max,
            'initial' => self::integer($schema['initial'] ?? $min, "{$where}.initial", $min, $max),
            'item' => self::schema(isset($item['type']) ? $item : ['type' => 'object', 'fields' => $item], "{$where}.item", false),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public static function initial(array $fields, array $props): array
    {
        return self::object($fields, [], $props, true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public static function normalize(array $fields, mixed $raw, array $props): array
    {
        $raw = is_array($raw) && ! array_is_list($raw) ? $raw : [];

        return self::object($fields, $raw, $props, $raw === []);
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function props(array $fields, mixed $raw): array
    {
        $raw = is_array($raw) && ! array_is_list($raw) ? $raw : [];
        $clean = [];
        foreach ($fields as $key => $schema) {
            $label = $schema['label'] ?? $key;
            $text = trim(is_scalar($raw[$key] ?? null) ? (string) $raw[$key] : '');
            if (($schema['required'] ?? false) && $text === '') {
                TemplateDocument::fail($schema['error'] ?? "«{$label}» es obligatorio.");
            }
            if (mb_strlen($text) > $schema['max']) {
                TemplateDocument::fail($schema['error'] ?? "«{$label}» admite hasta {$schema['max']} caracteres.");
            }
            $clean[$key] = $text;
        }

        return $clean;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function object(array $fields, array $raw, array $props, bool $fresh): array
    {
        $clean = [];
        foreach ($fields as $key => $schema) {
            $clean[$key] = self::value($schema, $raw[$key] ?? null, $props, $fresh && ! array_key_exists($key, $raw));
        }
        foreach ($fields as $key => $schema) {
            if (isset($schema['keep_if']) && ! Condition::holds($schema['keep_if'], new Scope($clean, $props, []))) {
                $clean[$key] = self::empty($schema, $props);
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $props
     */
    private static function value(array $schema, mixed $raw, array $props, bool $fresh): mixed
    {
        $label = $schema['label'] ?? 'Una celda';

        return match ($schema['type']) {
            'text' => self::text($schema, $raw, $label),
            'number' => self::number($schema, $raw, $label),
            'choice' => self::choice($schema, $raw, $label),
            'object' => self::object($schema['fields'], is_array($raw) && ! array_is_list($raw) ? $raw : [], $props, $fresh),
            'list' => self::list($schema, $raw, $props, $fresh, $label),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function text(array $schema, mixed $raw, string $label): string
    {
        if ($raw === null) {
            return '';
        }
        if (! is_string($raw) || mb_strlen($raw) > $schema['max']) {
            TemplateDocument::fail($schema['error'] ?? "{$label} supera los {$schema['max']} caracteres.");
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function number(array $schema, mixed $raw, string $label): string
    {
        $text = trim(is_string($raw) || is_int($raw) || is_float($raw) ? (string) $raw : '');
        if ($raw !== null && ! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            TemplateDocument::fail($schema['error'] ?? "{$label} debe ser un número.");
        }
        $pattern = '/^\d{1,'.$schema['digits'].'}'.($schema['decimals'] > 0 ? '([.,]\d{1,'.$schema['decimals'].'})?' : '').'$/';
        if ($text !== '' && preg_match($pattern, $text) !== 1) {
            TemplateDocument::fail($schema['error'] ?? "{$label} debe ser un número.");
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function choice(array $schema, mixed $raw, string $label): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw) || ! array_key_exists($raw, $schema['options'])) {
            TemplateDocument::fail($schema['error'] ?? "La respuesta de «{$label}» no es válida.");
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $props
     * @return list<mixed>
     */
    private static function list(array $schema, mixed $raw, array $props, bool $fresh, string $label): array
    {
        $given = is_array($raw) && array_is_list($raw);
        $items = $given ? $raw : [];
        if (count($items) > $schema['max']) {
            TemplateDocument::fail($schema['error'] ?? "«{$label}» admite hasta {$schema['max']} elementos.");
        }
        $count = ! $given && $fresh ? $schema['initial'] : max(count($items), $schema['min']);
        $clean = [];
        for ($index = 0; $index < $count; $index++) {
            $clean[] = self::value($schema['item'], $items[$index] ?? null, $props, ! $given && $fresh);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $props
     */
    private static function empty(array $schema, array $props): mixed
    {
        return match ($schema['type']) {
            'text', 'number' => '',
            'choice' => null,
            'object' => self::object($schema['fields'], [], $props, false),
            'list' => [],
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    public static function at(array $fields, string $path): ?array
    {
        $schema = ['type' => 'object', 'fields' => $fields];
        foreach (Scope::segments($path) as $segment) {
            if ($segment === '*') {
                if ($schema['type'] !== 'list') {
                    return null;
                }
                $schema = $schema['item'];

                continue;
            }
            if ($schema['type'] !== 'object' || ! isset($schema['fields'][$segment])) {
                return null;
            }
            $schema = $schema['fields'][$segment];
        }

        return $schema;
    }

    /**
     * @return array<string, string>
     */
    private static function options(mixed $options, string $where): array
    {
        if (! is_array($options) || $options === [] || array_is_list($options)) {
            BlockDefinitionError::fail("{$where}: escriba las opciones como {\"clave\": \"Texto\"}.");
        }
        $clean = [];
        foreach ($options as $key => $label) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                BlockDefinitionError::fail("{$where}: la opción «{$key}» debe usar minúsculas, números y _.");
            }
            $clean[$key] = self::string($label, "{$where}.{$key}", 120);
        }

        return $clean;
    }

    private static function string(mixed $value, string $where, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            BlockDefinitionError::fail("{$where}: escriba un texto de hasta {$max} caracteres.");
        }

        return $value;
    }

    private static function integer(mixed $value, string $where, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            BlockDefinitionError::fail("{$where}: debe ser un número entero entre {$min} y {$max}.");
        }

        return $value;
    }

    private static function boolean(mixed $value, string $where): bool
    {
        if (! is_bool($value)) {
            BlockDefinitionError::fail("{$where}: debe ser true o false.");
        }

        return $value;
    }
}
