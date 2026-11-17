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

    public const HELP_LENGTH = 200;

    public const DISPLAYS = ['lista', 'desplegable'];

    public const KINDS = [
        'texto' => [
            'label' => 'Texto libre',
            'schema' => ['type' => 'string', 'maxLength' => self::TEXT_LENGTH, 'multiline' => true, 'errorMessage' => 'Escriba una respuesta de hasta 3000 caracteres.'],
            'settings' => ['maxLength'],
        ],
        'una' => [
            'label' => 'Elegir una',
            'schema' => ['type' => 'string', 'oneOf' => [], 'errorMessage' => 'Elija solo entre las opciones de la respuesta.'],
            'settings' => ['display'],
        ],
        'varias' => [
            'label' => 'Elegir varias',
            'schema' => ['type' => 'array', 'errorMessage' => 'Elija solo entre las opciones de la respuesta.', 'items' => ['type' => 'string', 'oneOf' => [], 'errorMessage' => 'Elija solo entre las opciones de la respuesta.']],
            'settings' => ['minChoices', 'maxChoices'],
        ],
    ];

    public const SETTINGS = [
        'required' => true,
        'help' => '',
        'maxLength' => self::TEXT_LENGTH,
        'minChoices' => null,
        'maxChoices' => null,
        'display' => 'lista',
    ];

    public const COMMON_SETTINGS = ['required', 'help'];

    public const OWN_OPTIONS = 'customOptions';

    private const OPTION_KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    public static function attrs(array $attrs): array
    {
        $kind = $attrs['kind'] ?? null;
        if (! is_string($kind) || ! isset(self::KINDS[$kind])) {
            TemplateDocument::fail('Elija un tipo de respuesta: '.self::labels().'.');
        }
        $options = self::options($kind, $attrs['options'] ?? []);
        $settings = self::settings($kind, $options, $attrs);
        $raw = $attrs['value'] ?? null;
        $schema = self::schema($kind, $options);
        if ($schema === null) {
            if ($raw !== null && $raw !== '' && $raw !== []) {
                TemplateDocument::fail('Esta respuesta todavía no tiene opciones para elegir.');
            }

            return ['kind' => $kind, 'options' => $options, 'value' => self::emptyValue($kind), ...$settings];
        }
        $own = ($attrs[self::OWN_OPTIONS] ?? null) === true ? [self::OWN_OPTIONS => true] : [];
        $limit = $settings['maxLength'] ?? null;
        if (is_int($limit)) {
            $schema['maxLength'] = $limit;
            $schema['errorMessage'] = "Escriba una respuesta de hasta {$limit} caracteres.";
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
        if (is_array($value) && $value !== []) {
            self::assertChoices(count($value), self::setting($settings, 'minChoices'), self::setting($settings, 'maxChoices'));
        }

        return ['kind' => $kind, 'options' => $options, 'value' => $value, ...$settings, ...(self::hasOptions($kind) ? $own : [])];
    }

    /**
     * @param  array<array-key, mixed>  $attrs
     */
    public static function setting(array $attrs, string $name): mixed
    {
        $value = $attrs[$name] ?? null;

        return $value ?? self::SETTINGS[$name] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $attrs
     */
    public static function required(array $attrs): bool
    {
        return self::setting($attrs, 'required') !== false;
    }

    /**
     * @return list<string>
     */
    public static function violations(mixed $document): array
    {
        $messages = [];
        foreach (self::found($document) as $attrs) {
            try {
                self::attrs($attrs);
            } catch (ValidationException $exception) {
                $messages[] = (string) collect($exception->errors())->flatten()->first();
            }
        }

        return array_values(array_unique($messages));
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
    public static function sameQuestion(array $template, array $value, bool $career = false): bool
    {
        if (($template['kind'] ?? null) !== ($value['kind'] ?? null)) {
            return false;
        }

        if (self::chosenSettings($template) !== self::chosenSettings($value)) {
            return false;
        }

        return ($template['options'] ?? []) === ($value['options'] ?? [])
            || ($career && (self::needsOptions($template) || (($value[self::OWN_OPTIONS] ?? null) === true && self::adjustable($template))));
    }

    /**
     * @param  array<array-key, mixed>  $attrs
     */
    public static function adjustable(array $attrs): bool
    {
        return self::hasOptions(is_string($attrs['kind'] ?? null) ? $attrs['kind'] : '') && ($attrs['options'] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function ownOptions(array $template, array $value, bool $saving): array
    {
        $own = ($value[self::OWN_OPTIONS] ?? null) === true;
        unset($value[self::OWN_OPTIONS]);
        if (! self::adjustable($template) || ($template['kind'] ?? null) !== ($value['kind'] ?? null) || self::chosenSettings($template) !== self::chosenSettings($value)) {
            return $value;
        }
        $differs = ($value['options'] ?? []) !== ($template['options'] ?? []);
        if ($saving) {
            if (($value['options'] ?? []) === []) {
                TemplateDocument::fail('Deje al menos una opción en la respuesta de la plantilla.');
            }

            return $differs ? [...$value, self::OWN_OPTIONS => true] : $value;
        }
        if (! $differs) {
            return $value;
        }

        return $own ? [...$value, self::OWN_OPTIONS => true] : self::carry($template, $value);
    }

    /**
     * @return array<string, mixed>|list<mixed>|mixed
     */
    public static function withoutOwnOptions(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (($node['type'] ?? null) === self::NODE && is_array($node['attrs'] ?? null)) {
            unset($node['attrs'][self::OWN_OPTIONS]);
        }
        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(self::withoutOwnOptions(...), $node['content']);
        }

        return $node;
    }

    public static function assertEnoughOptions(mixed $node): void
    {
        if (! is_array($node)) {
            return;
        }
        $attrs = ($node['type'] ?? null) === self::NODE && is_array($node['attrs'] ?? null) ? $node['attrs'] : null;
        if ($attrs !== null && self::hasOptions(is_string($attrs['kind'] ?? null) ? $attrs['kind'] : '')) {
            $count = is_array($attrs['options'] ?? null) ? count($attrs['options']) : 0;
            $plural = fn (int $limit): string => $limit === 1 ? '1 opción' : "{$limit} opciones";
            $min = $attrs['minChoices'] ?? null;
            $max = $attrs['maxChoices'] ?? null;
            if (is_int($min) && $min > $count) {
                TemplateDocument::fail("Esta respuesta pide elegir al menos {$min}: deje al menos {$plural($min)}.");
            }
            if (is_int($max) && $max > $count) {
                TemplateDocument::fail("Esta respuesta permite elegir hasta {$max}: deje al menos {$plural($max)}.");
            }
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            self::assertEnoughOptions($child);
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $value
     */
    public static function sameAnswer(array $template, array $value): bool
    {
        return ($template['kind'] ?? null) === ($value['kind'] ?? null)
            && self::chosenSettings($template) === self::chosenSettings($value)
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

        /** @var array<string, mixed> $content */
        $content = self::withoutOwnOptions(self::unanswered($content));

        return $content;
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
            } elseif (! self::answered($attrs) && self::required($attrs)) {
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

    /**
     * @param  array<array-key, mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function chosenSettings(array $attrs): array
    {
        $chosen = [];
        foreach (array_keys(self::SETTINGS) as $name) {
            $value = $attrs[$name] ?? null;
            if ($value !== null && $value !== self::SETTINGS[$name]) {
                $chosen[$name] = $value;
            }
        }

        return $chosen;
    }

    /**
     * @param  list<array{const: string, title: string}>  $options
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function settings(string $kind, array $options, array $attrs): array
    {
        $allowed = [...self::COMMON_SETTINGS, ...self::KINDS[$kind]['settings']];
        $settings = [];
        foreach (array_keys(self::SETTINGS) as $name) {
            $raw = $attrs[$name] ?? null;
            if ($raw === null) {
                continue;
            }
            if (! in_array($name, $allowed, true)) {
                TemplateDocument::fail('Una respuesta de '.mb_strtolower(self::label($kind)).' no lleva esa opción.');
            }
            $value = match ($name) {
                'required' => is_bool($raw) ? $raw : TemplateDocument::fail('Indique si la respuesta es obligatoria.'),
                'help' => self::help($raw),
                'maxLength' => is_int($raw) && $raw >= 1 && $raw <= self::TEXT_LENGTH ? $raw : TemplateDocument::fail('El límite de caracteres va de 1 a '.self::TEXT_LENGTH.'.'),
                'minChoices', 'maxChoices' => self::choiceLimit($raw, $options),
                'display' => is_string($raw) && in_array($raw, self::DISPLAYS, true) ? $raw : TemplateDocument::fail('Elija cómo se muestra: '.implode(' o ', self::DISPLAYS).'.'),
            };
            if ($value !== self::SETTINGS[$name]) {
                $settings[$name] = $value;
            }
        }
        if (isset($settings['minChoices'], $settings['maxChoices']) && $settings['minChoices'] > $settings['maxChoices']) {
            TemplateDocument::fail('El mínimo para elegir no puede superar el máximo.');
        }

        return $settings;
    }

    private static function help(mixed $raw): string
    {
        $help = is_string($raw) ? trim((string) preg_replace('/\s+/u', ' ', $raw)) : null;
        if ($help === null || mb_strlen($help) > self::HELP_LENGTH) {
            TemplateDocument::fail('Escriba una ayuda de hasta '.self::HELP_LENGTH.' caracteres.');
        }

        return $help;
    }

    /**
     * @param  list<array{const: string, title: string}>  $options
     */
    private static function choiceLimit(mixed $raw, array $options): int
    {
        if ($options === []) {
            TemplateDocument::fail('Escriba las opciones antes de fijar cuántas elegir.');
        }
        $count = count($options);
        if (! is_int($raw) || $raw < 1 || $raw > $count) {
            TemplateDocument::fail("Cuántas elegir va de 1 a {$count}, el número de opciones.");
        }

        return $raw;
    }

    private static function assertChoices(int $count, mixed $min, mixed $max): void
    {
        $plural = fn (int $limit): string => $limit === 1 ? '1 opción' : "{$limit} opciones";
        if (is_int($min) && $count < $min) {
            TemplateDocument::fail('Elija al menos '.$plural($min).'.');
        }
        if (is_int($max) && $count > $max) {
            TemplateDocument::fail('Elija como máximo '.$plural($max).'.');
        }
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
