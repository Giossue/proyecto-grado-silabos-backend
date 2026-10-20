<?php

namespace App\Modules\Configuration\Domain\Blocks;

final class Condition
{
    private const SOURCES = ['value', 'prop', 'item', 'variable'];

    private const TESTS = ['equals', 'empty', 'zero'];

    /**
     * @return array<string, mixed>
     */
    public static function define(mixed $raw, string $where): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            BlockDefinitionError::fail("{$where}: escriba la condición como objeto.");
        }
        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $raw)) {
                if (count($raw) !== 1 || ! is_array($raw[$group]) || ! array_is_list($raw[$group]) || $raw[$group] === []) {
                    BlockDefinitionError::fail("{$where}.{$group}: debe ser una lista de condiciones.");
                }

                return [$group => array_map(fn (mixed $child, int $index): array => self::define($child, "{$where}.{$group}.{$index}"), $raw[$group], array_keys($raw[$group]))];
            }
        }
        if (array_key_exists('not', $raw)) {
            if (count($raw) !== 1) {
                BlockDefinitionError::fail("{$where}.not: no combine «not» con otras claves.");
            }

            return ['not' => self::define($raw['not'], "{$where}.not")];
        }
        $sources = array_values(array_intersect(self::SOURCES, array_keys($raw)));
        $tests = array_values(array_intersect(self::TESTS, array_keys($raw)));
        if (count($sources) !== 1 || count($tests) !== 1 || count($raw) !== 2) {
            BlockDefinitionError::fail("{$where}: use una fuente (".implode(', ', self::SOURCES).') y una prueba ('.implode(', ', self::TESTS).').');
        }
        [$source, $test] = [$sources[0], $tests[0]];
        if (! is_string($raw[$source]) || trim($raw[$source]) === '') {
            BlockDefinitionError::fail("{$where}.{$source}: escriba una ruta o una clave.");
        }
        if ($test !== 'equals' && ! is_bool($raw[$test])) {
            BlockDefinitionError::fail("{$where}.{$test}: debe ser true o false.");
        }
        if ($test === 'equals' && ! is_scalar($raw[$test]) && $raw[$test] !== null) {
            BlockDefinitionError::fail("{$where}.equals: debe ser un texto, un número o null.");
        }

        return [$source => $raw[$source], $test => $raw[$test]];
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    public static function holds(array $condition, Scope $scope): bool
    {
        if (isset($condition['all'])) {
            return array_reduce($condition['all'], fn (bool $carry, array $child): bool => $carry && self::holds($child, $scope), true);
        }
        if (isset($condition['any'])) {
            return array_reduce($condition['any'], fn (bool $carry, array $child): bool => $carry || self::holds($child, $scope), false);
        }
        if (isset($condition['not'])) {
            return ! self::holds($condition['not'], $scope);
        }
        $value = match (true) {
            array_key_exists('value', $condition) => $scope->value($condition['value']),
            array_key_exists('prop', $condition) => $scope->prop($condition['prop']),
            array_key_exists('item', $condition) => $scope->aliased($condition['item']),
            default => $scope->variable($condition['variable']),
        };
        if (array_key_exists('equals', $condition)) {
            return $condition['equals'] === null ? $value === null : is_scalar($value) && (string) $value === (string) $condition['equals'];
        }
        if (array_key_exists('empty', $condition)) {
            return self::isEmpty($value) === $condition['empty'];
        }
        $text = is_scalar($value) ? trim((string) $value) : '';
        $zero = $text !== '' && Numbers::parse($text) === 0.0;

        return $zero === $condition['zero'];
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    public static function variables(array $condition): array
    {
        foreach (['all', 'any'] as $group) {
            if (isset($condition[$group])) {
                return array_merge(...array_map(self::variables(...), $condition[$group]));
            }
        }
        if (isset($condition['not'])) {
            return self::variables($condition['not']);
        }

        return isset($condition['variable']) ? [$condition['variable']] : [];
    }
}
