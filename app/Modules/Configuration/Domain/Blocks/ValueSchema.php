<?php

namespace App\Modules\Configuration\Domain\Blocks;

use App\Modules\Configuration\Domain\TemplateDocument;

final class ValueSchema
{
    public const TYPES = ['string', 'number', 'object', 'array'];

    private const KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    private const MULTIPLES = [1, 0.1, 0.01, 0.001, 0.0001, 0.00001, 0.000001];

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
            $clean[$key] = self::schema(is_array($schema) ? self::upgrade($schema) : $schema, "{$where}.{$key}", $props);
        }

        return $clean;
    }

    /**
     * Traduce los nombres anteriores (`text`, `choice`, `list`, `fields`, `max`…) a los de
     * JSON Schema. Así se leen también las plantillas guardadas en revisiones antiguas.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function upgrade(array $schema): array
    {
        foreach (['label' => 'title', 'error' => 'errorMessage'] as $old => $new) {
            if (array_key_exists($old, $schema)) {
                $schema[$new] ??= $schema[$old];
                unset($schema[$old]);
            }
        }
        unset($schema['from']);
        switch ($schema['type'] ?? null) {
            case 'text':
                $schema['type'] = 'string';
                if (array_key_exists('max', $schema)) {
                    $schema['maxLength'] ??= $schema['max'];
                    unset($schema['max']);
                }
                if (($schema['required'] ?? false) === true) {
                    $schema['minLength'] ??= 1;
                }
                unset($schema['required']);
                break;
            case 'choice':
                $schema['type'] = 'string';
                $options = is_array($schema['options'] ?? null) ? $schema['options'] : [];
                $schema['oneOf'] ??= array_map(fn (string|int $key, mixed $title): array => ['const' => (string) $key, 'title' => $title], array_keys($options), $options);
                unset($schema['options']);
                break;
            case 'number':
                if (array_key_exists('digits', $schema) || array_key_exists('decimals', $schema)) {
                    $digits = is_int($schema['digits'] ?? null) ? $schema['digits'] : 3;
                    $decimals = is_int($schema['decimals'] ?? null) ? $schema['decimals'] : 2;
                    $schema['multipleOf'] ??= $decimals > 0 ? round(10 ** -$decimals, $decimals) : 1;
                    $schema['maximum'] ??= $decimals > 0 ? round(10 ** $digits - 10 ** -$decimals, $decimals) : 10 ** $digits - 1;
                    unset($schema['digits'], $schema['decimals']);
                }
                break;
            case 'object':
                if (array_key_exists('fields', $schema)) {
                    $schema['properties'] ??= $schema['fields'];
                    unset($schema['fields']);
                }
                break;
            case 'list':
                $schema['type'] = 'array';
                foreach (['item' => 'items', 'min' => 'minItems', 'max' => 'maxItems'] as $old => $new) {
                    if (array_key_exists($old, $schema)) {
                        $schema[$new] ??= $schema[$old];
                        unset($schema[$old]);
                    }
                }
                if (is_array($schema['items'] ?? null) && ! isset($schema['items']['type'])) {
                    $schema['items'] = ['type' => 'object', 'properties' => $schema['items']];
                }
                break;
        }
        if (is_array($schema['properties'] ?? null)) {
            $schema['properties'] = self::upgradeAll($schema['properties']);
        }
        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = self::upgrade($schema['items']);
        }

        return $schema;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<array-key, mixed>
     */
    public static function upgradeAll(array $fields): array
    {
        return array_map(fn (mixed $schema): mixed => is_array($schema) ? self::upgrade($schema) : $schema, $fields);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public static function isChoice(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'string' && isset($schema['oneOf']);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public static function optionTitle(array $schema, mixed $value): string
    {
        foreach ($schema['oneOf'] ?? [] as $option) {
            if ($option['const'] === $value) {
                return $option['title'];
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{int, int}
     */
    public static function digits(array $schema): array
    {
        $decimals = $schema['multipleOf'] >= 1 ? 0 : (int) round(-log10($schema['multipleOf']));

        return [strlen((string) (int) floor($schema['maximum'])), $decimals];
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
        if ($props && ($type !== 'string' || isset($schema['oneOf']))) {
            BlockDefinitionError::fail("{$where}: las propiedades solo admiten texto («string»).");
        }
        $clean = ['type' => $type];
        foreach (['title', 'errorMessage'] as $text) {
            if (isset($schema[$text])) {
                $clean[$text] = self::string($schema[$text], "{$where}.{$text}", 300);
            }
        }
        if (isset($schema['keep_if'])) {
            $clean['keep_if'] = Condition::define($schema['keep_if'], "{$where}.keep_if");
        }
        match ($type) {
            'string' => $clean += isset($schema['oneOf'])
                ? ['oneOf' => self::options($schema['oneOf'], "{$where}.oneOf")]
                : [
                    'maxLength' => self::integer($schema['maxLength'] ?? 1000, "{$where}.maxLength", 1, 20000),
                    ...(isset($schema['minLength']) ? ['minLength' => self::integer($schema['minLength'], "{$where}.minLength", 0, 1)] : []),
                    'multiline' => self::boolean($schema['multiline'] ?? false, "{$where}.multiline"),
                ],
            'number' => $clean += self::numberSchema($schema, $where),
            'object' => $clean['properties'] = self::define($schema['properties'] ?? null, "{$where}.properties"),
            'array' => $clean += self::arraySchema($schema, $where),
        };

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{multipleOf: int|float, maximum: int|float}
     */
    private static function numberSchema(array $schema, string $where): array
    {
        $multiple = $schema['multipleOf'] ?? 0.01;
        if ((! is_int($multiple) && ! is_float($multiple)) || ! in_array($multiple, self::MULTIPLES, false)) {
            BlockDefinitionError::fail("{$where}.multipleOf: use 1, 0.1, 0.01… hasta 0.000001.");
        }
        $maximum = $schema['maximum'] ?? 999.99;
        if ((! is_int($maximum) && ! is_float($maximum)) || $maximum <= 0 || $maximum >= 1_000_000_000) {
            BlockDefinitionError::fail("{$where}.maximum: debe ser un número mayor que 0 y menor que 1 000 000 000.");
        }

        return ['multipleOf' => $multiple, 'maximum' => $maximum];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function arraySchema(array $schema, string $where): array
    {
        $min = self::integer($schema['minItems'] ?? 0, "{$where}.minItems", 0, 100);
        $max = self::integer($schema['maxItems'] ?? 100, "{$where}.maxItems", max(1, $min), 100);
        $items = $schema['items'] ?? null;
        if (! is_array($items)) {
            BlockDefinitionError::fail("{$where}.items: falta el esquema de cada elemento.");
        }

        return [
            'minItems' => $min,
            'maxItems' => $max,
            'initial' => self::integer($schema['initial'] ?? $min, "{$where}.initial", $min, $max),
            'items' => self::schema($items, "{$where}.items", false),
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
            $label = $schema['title'] ?? $key;
            $text = trim(is_scalar($raw[$key] ?? null) ? (string) $raw[$key] : '');
            if (($schema['minLength'] ?? 0) > 0 && $text === '') {
                TemplateDocument::fail($schema['errorMessage'] ?? "«{$label}» es obligatorio.");
            }
            if (mb_strlen($text) > $schema['maxLength']) {
                TemplateDocument::fail($schema['errorMessage'] ?? "«{$label}» admite hasta {$schema['maxLength']} caracteres.");
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
        $label = $schema['title'] ?? 'Una celda';

        return match ($schema['type']) {
            'string' => self::isChoice($schema) ? self::choice($schema, $raw, $label) : self::text($schema, $raw, $label),
            'number' => self::number($schema, $raw, $label),
            'object' => self::object($schema['properties'], is_array($raw) && ! array_is_list($raw) ? $raw : [], $props, $fresh),
            'array' => self::list($schema, $raw, $props, $fresh, $label),
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
        if (! is_string($raw) || mb_strlen($raw) > $schema['maxLength']) {
            TemplateDocument::fail($schema['errorMessage'] ?? "{$label} supera los {$schema['maxLength']} caracteres.");
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
            TemplateDocument::fail($schema['errorMessage'] ?? "{$label} debe ser un número.");
        }
        [$digits, $decimals] = self::digits($schema);
        $pattern = '/^\d{1,'.$digits.'}'.($decimals > 0 ? '([.,]\d{1,'.$decimals.'})?' : '').'$/';
        if ($text !== '' && (preg_match($pattern, $text) !== 1 || Numbers::parse($text) > $schema['maximum'])) {
            TemplateDocument::fail($schema['errorMessage'] ?? "{$label} debe ser un número.");
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
        if (! is_string($raw) || self::optionTitle($schema, $raw) === '') {
            TemplateDocument::fail($schema['errorMessage'] ?? "La respuesta de «{$label}» no es válida.");
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
        if (count($items) > $schema['maxItems']) {
            TemplateDocument::fail($schema['errorMessage'] ?? "«{$label}» admite hasta {$schema['maxItems']} elementos.");
        }
        $count = ! $given && $fresh ? $schema['initial'] : max(count($items), $schema['minItems']);
        $clean = [];
        for ($index = 0; $index < $count; $index++) {
            $clean[] = self::value($schema['items'], $items[$index] ?? null, $props, ! $given && $fresh);
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
            'string' => self::isChoice($schema) ? null : '',
            'number' => '',
            'object' => self::object($schema['properties'], [], $props, false),
            'array' => [],
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    public static function at(array $fields, string $path): ?array
    {
        $schema = ['type' => 'object', 'properties' => $fields];
        foreach (Scope::segments($path) as $segment) {
            if ($segment === '*') {
                if ($schema['type'] !== 'array') {
                    return null;
                }
                $schema = $schema['items'];

                continue;
            }
            if ($schema['type'] !== 'object' || ! isset($schema['properties'][$segment])) {
                return null;
            }
            $schema = $schema['properties'][$segment];
        }

        return $schema;
    }

    /**
     * @return list<array{const: string, title: string}>
     */
    private static function options(mixed $options, string $where): array
    {
        if (! is_array($options) || $options === [] || ! array_is_list($options)) {
            BlockDefinitionError::fail("{$where}: escriba las opciones como [{\"const\": \"clave\", \"title\": \"Texto\"}].");
        }
        $clean = [];
        $seen = [];
        foreach ($options as $index => $option) {
            $key = is_array($option) ? ($option['const'] ?? null) : null;
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1 || isset($seen[$key])) {
                BlockDefinitionError::fail("{$where}.{$index}.const: use una clave única con minúsculas, números y _.");
            }
            $seen[$key] = true;
            $clean[] = ['const' => $key, 'title' => self::string($option['title'] ?? null, "{$where}.{$index}.title", 120)];
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
