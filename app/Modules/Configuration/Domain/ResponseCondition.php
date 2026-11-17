<?php

namespace App\Modules\Configuration\Domain;

use Illuminate\Validation\ValidationException;

final class ResponseCondition
{
    public const ID = 'id';

    public const ATTR = 'showIf';

    public const SOURCE_KINDS = ['una', 'varias'];

    private const KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    /**
     * @return array{response: string, options: list<string>}
     */
    public static function define(mixed $raw): array
    {
        $response = is_array($raw) ? ($raw['response'] ?? null) : null;
        $options = is_array($raw) ? ($raw['options'] ?? null) : null;
        if (! is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), ['response', 'options']) !== []
            || ! is_string($response) || preg_match(self::KEY, $response) !== 1
            || ! is_array($options) || ! array_is_list($options) || $options === [] || count($options) > ResponseKind::MAX_OPTIONS
            || array_filter($options, fn (mixed $option): bool => ! is_string($option) || preg_match(self::KEY, $option) !== 1) !== []) {
            TemplateDocument::fail('Elija la respuesta y al menos una de sus opciones para «Mostrar solo si».');
        }

        return ['response' => $response, 'options' => array_values(array_unique($options))];
    }

    public static function id(mixed $raw): string
    {
        if (! is_string($raw) || preg_match(self::KEY, $raw) !== 1) {
            TemplateDocument::fail('El identificador de la respuesta no es válido.');
        }

        return $raw;
    }

    /**
     * @param  array{response: string, options: list<string>}  $condition
     * @param  array<string, mixed>  $answers
     */
    public static function holds(array $condition, array $answers): bool
    {
        $value = $answers[$condition['response']] ?? null;
        $chosen = is_array($value) ? $value : [$value];

        return array_intersect($condition['options'], array_filter($chosen, is_string(...))) !== [];
    }

    /**
     * @param  array{response: string, options: list<string>}  $condition
     * @param  array<string, mixed>  $answers
     */
    public static function shows(array $condition, array $answers): bool
    {
        return ! array_key_exists($condition['response'], $answers) || self::holds($condition, $answers);
    }

    /**
     * @param  list<array{key: string, document: mixed}>  $fields
     * @param  array<string, array{response: string, options: list<string>}>  $fieldConditions
     * @return array{hidden: array<string, true>, answers: array<string, mixed>}
     */
    public static function evaluate(array $fields, array $fieldConditions): array
    {
        $hidden = [];
        $answers = [];
        foreach ($fields as $field) {
            $condition = $fieldConditions[$field['key']] ?? null;
            $shown = $condition === null || self::shows($condition, $answers);
            if (! $shown) {
                $hidden[$field['key']] = true;
            }
            foreach (self::responses($field['document']) as $attrs) {
                $id = $attrs[self::ID] ?? null;
                if (! is_string($id) || array_key_exists($id, $answers)) {
                    continue;
                }
                $visible = $shown && (! is_array($attrs[self::ATTR] ?? null) || self::shows(self::shape($attrs[self::ATTR]), $answers));
                $answers[$id] = $visible ? ($attrs['value'] ?? null) : null;
            }
        }

        return ['hidden' => $hidden, 'answers' => $answers];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public static function clear(mixed $document, array $answers, bool $optional = false): mixed
    {
        if (! is_array($document)) {
            return $document;
        }
        if (($document['type'] ?? null) === ResponseKind::NODE && is_array($document['attrs'] ?? null) && is_array($document['attrs'][self::ATTR] ?? null)
            && ! self::shows(self::shape($document['attrs'][self::ATTR]), $answers)) {
            $document = ResponseKind::unanswered($document);
            if ($optional) {
                $document['attrs']['required'] = false;
            }
        }
        if (is_array($document['content'] ?? null)) {
            $document['content'] = array_map(fn (mixed $child): mixed => self::clear($child, $answers, $optional), $document['content']);
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public static function shownResponses(mixed $document, array $answers): array
    {
        $shown = array_values(array_filter(self::responses($document), fn (array $attrs): bool => ! is_array($attrs[self::ATTR] ?? null) || self::shows(self::shape($attrs[self::ATTR]), $answers)));

        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => array_map(fn (array $attrs): array => ['type' => ResponseKind::NODE, 'attrs' => $attrs], $shown)]]];
    }

    /**
     * @param  list<array{path: string, document: mixed, show_if: mixed}>  $fields
     * @return list<array{path: string, message: string}>
     */
    public static function problems(array $fields): array
    {
        $problems = [];
        $seen = [];
        foreach ($fields as $field) {
            $earlier = $seen;
            if ($field['show_if'] !== null) {
                $message = self::invalid($field['show_if'], $earlier);
                if ($message !== null) {
                    $problems[] = ['path' => "{$field['path']}.show_if", 'message' => $message];
                }
            }
            foreach (self::responses($field['document']) as $attrs) {
                if (is_array($attrs[self::ATTR] ?? null)) {
                    $message = self::invalid($attrs[self::ATTR], $seen);
                    if ($message !== null) {
                        $problems[] = ['path' => "{$field['path']}.content", 'message' => $message];
                    }
                }
                $id = $attrs[self::ID] ?? null;
                if (is_string($id)) {
                    if (isset($seen[$id])) {
                        $problems[] = ['path' => "{$field['path']}.content", 'message' => 'Dos respuestas tienen el mismo identificador. Quite una y vuelva a insertarla.'];
                    }
                    $seen[$id] ??= $attrs;
                }
            }
        }

        return array_values(array_unique($problems, SORT_REGULAR));
    }

    /**
     * @param  array<string, array{response: string, options: list<string>}>  $fieldConditions
     * @param  iterable<mixed>  $documents
     * @return array<string, list<string>>
     */
    public static function usedOptions(array $fieldConditions, iterable $documents): array
    {
        $conditions = array_values($fieldConditions);
        foreach ($documents as $document) {
            foreach (self::responses($document) as $attrs) {
                if (is_array($attrs[self::ATTR] ?? null)) {
                    $conditions[] = self::shape($attrs[self::ATTR]);
                }
            }
        }
        $used = [];
        foreach ($conditions as $condition) {
            $used[$condition['response']] = array_values(array_unique([...($used[$condition['response']] ?? []), ...$condition['options']]));
        }

        return $used;
    }

    /**
     * @param  array<string, list<string>>  $used
     */
    public static function assertKeepsUsedOptions(mixed $document, array $used): void
    {
        foreach (self::responses($document) as $attrs) {
            $id = $attrs[self::ID] ?? null;
            if (! is_string($id) || ! isset($used[$id])) {
                continue;
            }
            $kept = array_column(is_array($attrs['options'] ?? null) ? $attrs['options'] : [], 'const');
            $missing = array_diff($used[$id], $kept);
            if ($missing !== []) {
                TemplateDocument::fail('No puede quitar una opción que la plantilla usa para mostrar u ocultar otra parte del sílabo. Déjela o pida a Administración que cambie esa condición.');
            }
        }
    }

    /**
     * @return array<string, mixed>|list<mixed>|mixed
     */
    public static function withoutLogic(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (($node['type'] ?? null) === ResponseKind::NODE && is_array($node['attrs'] ?? null)) {
            unset($node['attrs'][self::ID], $node['attrs'][self::ATTR]);
        }
        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(self::withoutLogic(...), $node['content']);
        }

        return $node;
    }

    /**
     * @param  array<array-key, mixed>  $template
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function stamp(array $template, array $value): array
    {
        unset($value[self::ID], $value[self::ATTR]);
        foreach ([self::ID, self::ATTR] as $name) {
            if (isset($template[$name])) {
                $value[$name] = $template[$name];
            }
        }

        return $value;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public static function responses(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        $found = ($node['type'] ?? null) === ResponseKind::NODE && is_array($node['attrs'] ?? null) ? [$node['attrs']] : [];
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $found = [...$found, ...self::responses($child)];
        }

        return $found;
    }

    /**
     * @return array{response: string, options: list<string>}
     */
    private static function shape(mixed $raw): array
    {
        $response = is_array($raw) && is_string($raw['response'] ?? null) ? $raw['response'] : '';
        $options = is_array($raw) && is_array($raw['options'] ?? null) ? array_values(array_filter($raw['options'], is_string(...))) : [];

        return ['response' => $response, 'options' => $options];
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $earlier
     */
    private static function invalid(mixed $raw, array $earlier): ?string
    {
        try {
            $condition = self::define($raw);
        } catch (ValidationException $exception) {
            return (string) collect($exception->errors())->flatten()->first();
        }
        $source = $earlier[$condition['response']] ?? null;
        if ($source === null) {
            return 'La condición «Mostrar solo si» usa una respuesta que ya no está antes de esta parte. Elija otra respuesta o quite la condición.';
        }
        if (! in_array($source['kind'] ?? null, self::SOURCE_KINDS, true)) {
            return 'La condición «Mostrar solo si» solo puede usar respuestas de elegir una o elegir varias.';
        }
        $keys = array_column(is_array($source['options'] ?? null) ? $source['options'] : [], 'const');
        if (array_diff($condition['options'], $keys) !== []) {
            return 'La condición «Mostrar solo si» usa una opción que la respuesta ya no tiene. Elija otra opción o quite la condición.';
        }

        return null;
    }
}
