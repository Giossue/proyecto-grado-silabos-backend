<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Domain\Blocks\ValueSchema;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use Illuminate\Validation\ValidationException;

final class ResponseKind
{
    public const NODE = 'response';

    public const MAX_OPTIONS = 50;

    public const OPTION_LENGTH = 120;

    public const TEXT_LENGTH = 3000;

    public const KINDS = [
        'texto' => [
            'label' => 'Texto libre',
            'schema' => ['type' => 'string', 'maxLength' => self::TEXT_LENGTH, 'multiline' => true, 'errorMessage' => 'Escriba una respuesta de hasta 3000 caracteres.'],
        ],
        'una' => [
            'label' => 'Elegir una',
            'schema' => ['type' => 'string', 'oneOf' => [], 'errorMessage' => 'Elija solo entre las opciones de la respuesta.'],
        ],
        'varias' => [
            'label' => 'Elegir varias',
            'schema' => ['type' => 'array', 'errorMessage' => 'Elija solo entre las opciones de la respuesta.', 'items' => ['type' => 'string', 'oneOf' => [], 'errorMessage' => 'Elija solo entre las opciones de la respuesta.']],
        ],
    ];

    private const OPTION_KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    /**
     * @param  array<string, mixed>  $attrs
     * @return array{kind: string, options: list<array{const: string, title: string}>, value: mixed}
     */
    public static function attrs(array $attrs): array
    {
        $kind = $attrs['kind'] ?? null;
        if (! is_string($kind) || ! isset(self::KINDS[$kind])) {
            TemplateDocument::fail('Elija un tipo de respuesta: '.self::labels().'.');
        }
        $options = self::options($kind, $attrs['options'] ?? []);
        $raw = $attrs['value'] ?? null;
        $schema = self::schema($kind, $options);
        if ($schema === null) {
            if ($raw !== null && $raw !== '' && $raw !== []) {
                TemplateDocument::fail('Esta respuesta todavía no tiene opciones para elegir.');
            }

            return ['kind' => $kind, 'options' => $options, 'value' => self::emptyValue($kind)];
        }
        if ($schema['type'] === 'array' && $raw !== null && (! is_array($raw) || ! array_is_list($raw))) {
            TemplateDocument::fail($schema['errorMessage']);
        }
        $value = ValueSchema::normalize(['respuesta' => $schema], ['respuesta' => $raw], [])['respuesta'];
        if (is_array($value)) {
            $value = array_values(array_unique(array_filter($value, is_string(...))));
        }
        if ($schema['type'] === 'string' && ! self::hasOptions($kind) && is_string($value)) {
            $value = trim($value);
        }

        return ['kind' => $kind, 'options' => $options, 'value' => $value];
    }

    public static function label(string $kind): string
    {
        return self::KINDS[$kind]['label'] ?? '';
    }

    /**
     * @param  array<array-key, mixed>  $attrs
     */
    public static function answered(array $attrs): bool
    {
        $value = $attrs['value'] ?? null;

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * @param  array<array-key, mixed>  $attrs
     */
    public static function needsOptions(array $attrs): bool
    {
        return self::hasOptions(is_string($attrs['kind'] ?? null) ? $attrs['kind'] : '') && ($attrs['options'] ?? []) === [];
    }

    public static function within(mixed $document): bool
    {
        return self::found($document) !== [];
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return list<string>
     */
    public static function lines(array $attrs): array
    {
        $kind = (string) ($attrs['kind'] ?? '');
        $options = is_array($attrs['options'] ?? null) ? $attrs['options'] : [];
        $value = $attrs['value'] ?? null;
        if (! self::answered($attrs) || ! isset(self::KINDS[$kind])) {
            return [];
        }
        if (! self::hasOptions($kind)) {
            return is_scalar($value) ? (preg_split('/\R/u', (string) $value) ?: []) : [];
        }
        $choice = ['type' => 'string', 'oneOf' => $options];

        return array_values(array_filter(
            array_map(fn (mixed $chosen): string => ValueSchema::optionTitle($choice, $chosen), is_array($value) ? $value : [$value]),
            fn (string $title): bool => $title !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     */
    public static function sameQuestion(array $template, array $value, bool $optionsPending = false): bool
    {
        if (($template['kind'] ?? null) !== ($value['kind'] ?? null)) {
            return false;
        }

        return ($template['options'] ?? []) === ($value['options'] ?? []) || ($optionsPending && self::needsOptions($template));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     */
    public static function sameAnswer(array $template, array $value): bool
    {
        return ($template['kind'] ?? null) === ($value['kind'] ?? null)
            && ($template['options'] ?? []) == ($value['options'] ?? [])
            && ($template['value'] ?? null) == ($value['value'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function carry(array $template, array $value): array
    {
        if (($template['kind'] ?? null) !== ($value['kind'] ?? null)) {
            return $template;
        }
        $keys = array_column(is_array($template['options'] ?? null) ? $template['options'] : [], 'const');
        $answer = $value['value'] ?? null;
        if (self::hasOptions((string) $template['kind'])) {
            $answer = is_array($answer)
                ? array_values(array_filter($answer, fn (mixed $chosen): bool => in_array($chosen, $keys, true)))
                : (in_array($answer, $keys, true) ? $answer : null);
        }
        try {
            return self::attrs([...$template, 'value' => $answer]);
        } catch (ValidationException) {
            return $template;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function unanswered(array $node): array
    {
        if (($node['type'] ?? null) === self::NODE) {
            $node['attrs']['value'] = self::emptyValue((string) ($node['attrs']['kind'] ?? ''));
        }
        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(fn (array $child): array => self::unanswered($child), $node['content']);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function forField(string $responsible, array $content): array
    {
        if ($responsible === FieldDefinition::INSTITUTIONAL && self::within($content)) {
            throw ValidationException::withMessages(['content' => 'Un campo Institucional no lleva tipos de respuesta. Cambie quién lo llena o quite la respuesta.']);
        }

        return self::unanswered($content);
    }

    /**
     * @return array{unanswered: int, without_options: int}
     */
    public static function pending(mixed $document): array
    {
        $pending = ['unanswered' => 0, 'without_options' => 0];
        foreach (self::found($document) as $attrs) {
            if (self::needsOptions($attrs)) {
                $pending['without_options']++;
            } elseif (! self::answered($attrs)) {
                $pending['unanswered']++;
            }
        }

        return $pending;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function found(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        $found = ($node['type'] ?? null) === self::NODE ? [is_array($node['attrs'] ?? null) ? $node['attrs'] : []] : [];
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $found = [...$found, ...self::found($child)];
        }

        return $found;
    }

    private static function hasOptions(string $kind): bool
    {
        $schema = self::KINDS[$kind]['schema'] ?? [];

        return isset($schema['oneOf']) || isset($schema['items']['oneOf']);
    }

    private static function emptyValue(string $kind): mixed
    {
        return match (self::KINDS[$kind]['schema']['type'] ?? null) {
            'array' => [],
            'string' => self::hasOptions($kind) ? null : '',
            default => null,
        };
    }

    private static function labels(): string
    {
        $labels = array_column(self::KINDS, 'label');
        $last = array_pop($labels);

        return implode(', ', $labels).' o '.$last;
    }

    /**
     * @param  list<array{const: string, title: string}>  $options
     * @return array<string, mixed>|null
     */
    private static function schema(string $kind, array $options): ?array
    {
        $schema = self::KINDS[$kind]['schema'];
        if (! self::hasOptions($kind)) {
            return ValueSchema::define(['respuesta' => $schema], 'respuesta')['respuesta'];
        }
        if ($options === []) {
            return null;
        }
        if (isset($schema['items'])) {
            $schema['items']['oneOf'] = $options;
        } else {
            $schema['oneOf'] = $options;
        }

        return ValueSchema::define(['respuesta' => $schema], 'respuesta')['respuesta'];
    }

    /**
     * @return list<array{const: string, title: string}>
     */
    private static function options(string $kind, mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            TemplateDocument::fail('Las opciones de la respuesta no son válidas.');
        }
        if (! self::hasOptions($kind)) {
            if ($raw !== []) {
                TemplateDocument::fail('Una respuesta de '.mb_strtolower(self::label($kind)).' no lleva opciones.');
            }

            return [];
        }
        if (count($raw) > self::MAX_OPTIONS) {
            TemplateDocument::fail('Una respuesta admite hasta '.self::MAX_OPTIONS.' opciones.');
        }
        $options = [];
        foreach ($raw as $option) {
            $key = is_array($option) ? ($option['const'] ?? null) : null;
            $title = is_array($option) && is_string($option['title'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $option['title'])) : '';
            if ($title === '' || mb_strlen($title) > self::OPTION_LENGTH) {
                TemplateDocument::fail('Escriba el texto de cada opción, de hasta '.self::OPTION_LENGTH.' caracteres.');
            }
            if (! is_string($key) || preg_match(self::OPTION_KEY, $key) !== 1 || in_array($key, array_column($options, 'const'), true)) {
                TemplateDocument::fail('Las opciones de la respuesta no son válidas.');
            }
            if (in_array(mb_strtolower($title), array_map(mb_strtolower(...), array_column($options, 'title')), true)) {
                TemplateDocument::fail('Hay opciones repetidas. Deje cada opción una sola vez.');
            }
            $options[] = ['const' => $key, 'title' => $title];
        }

        return $options;
    }
}
