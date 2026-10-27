<?php

namespace App\Modules\Configuration\Domain\Blocks;

final class BlockRules
{
    private const CODE = '/^[a-z][a-z0-9_]{0,60}$/';

    /**
     * @param  array<string, array<string, mixed>>  $value
     * @param  list<string>  $variables
     * @return list<array<string, mixed>>
     */
    public static function define(mixed $raw, string $where, array $value, array $variables): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            BlockDefinitionError::fail("{$where}: las reglas son una lista.");
        }
        $codes = [];
        $rules = [];
        foreach ($raw as $index => $rule) {
            $at = "{$where}.{$index}";
            if (! is_array($rule) || ! is_string($rule['code'] ?? null) || preg_match(self::CODE, $rule['code']) !== 1 || in_array($rule['code'], $codes, true)) {
                BlockDefinitionError::fail("{$at}.code: escriba un código único en minúsculas.");
            }
            $codes[] = $rule['code'];
            if (! in_array($rule['on'] ?? null, ['save', 'submit'], true)) {
                BlockDefinitionError::fail("{$at}.on: use «save» (se rechaza al guardar) o «submit» (impide enviar).");
            }
            if (! is_string($rule['message'] ?? null) || trim($rule['message']) === '' || mb_strlen($rule['message']) > 500) {
                BlockDefinitionError::fail("{$at}.message: escriba el mensaje (hasta 500 caracteres).");
            }
            $checks = $rule['checks'] ?? null;
            if (! is_array($checks) || ! array_is_list($checks) || $checks === []) {
                BlockDefinitionError::fail("{$at}.checks: escriba al menos una comprobación.");
            }
            $rules[] = [
                'code' => $rule['code'],
                'on' => $rule['on'],
                'message' => $rule['message'],
                'checks' => array_map(fn (mixed $check, int $position): array => self::check($check, "{$at}.checks.{$position}", $value, $variables), $checks, array_keys($checks)),
            ] + (is_string($rule['hint'] ?? null) && trim($rule['hint']) !== '' ? ['hint' => $rule['hint']] : []);
        }

        return $rules;
    }

    /**
     * @param  array<string, array<string, mixed>>  $value
     * @param  list<string>  $variables
     * @return array<string, mixed>
     */
    private static function check(mixed $raw, string $where, array $value, array $variables): array
    {
        if (! is_array($raw)) {
            BlockDefinitionError::fail("{$where}: escriba la comprobación como objeto.");
        }
        $measures = array_values(array_intersect(['count', 'filled', 'sum'], array_keys($raw)));
        $comparisons = array_values(array_intersect(['equals', 'max', 'min'], array_keys($raw)));
        if (count($measures) !== 1 || count($comparisons) !== 1) {
            BlockDefinitionError::fail("{$where}: use «count», «filled» o «sum» y una comparación: equals, max o min.");
        }
        $path = $raw[$measures[0]];
        if (! is_string($path) || ValueSchema::at($value, $path) === null) {
            BlockDefinitionError::fail("{$where}.{$measures[0]}: «".(is_string($path) ? $path : '').'» no existe en el valor del bloque.');
        }
        $target = $raw[$comparisons[0]];
        if (is_array($target)) {
            $byVariable = is_string($target['variable'] ?? null) && in_array($target['variable'], $variables, true);
            $byCount = is_string($target['count'] ?? null) && ValueSchema::at($value, $target['count']) !== null;
            if (count($target) !== 1 || (! $byVariable && ! $byCount)) {
                BlockDefinitionError::fail("{$where}.{$comparisons[0]}: use un número, {\"variable\": \"clave\"} del catálogo o {\"count\": \"lista\"} del valor.");
            }
        } elseif (! is_int($target) && ! is_float($target)) {
            BlockDefinitionError::fail("{$where}.{$comparisons[0]}: use un número, {\"variable\": \"clave\"} o {\"count\": \"lista\"}.");
        }
        $check = [$measures[0] => $path, $comparisons[0] => $target];
        if (isset($raw['label'])) {
            if (! is_string($raw['label']) || trim($raw['label']) === '' || mb_strlen($raw['label']) > 60) {
                BlockDefinitionError::fail("{$where}.label: escriba un nombre corto.");
            }
            $check['label'] = $raw['label'];
        }

        return $check;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $value
     * @param  array<string, string>  $variables
     * @return list<array{code: string, message: string}>
     */
    public static function failures(array $rules, array $value, array $variables, string $on): array
    {
        $failures = [];
        foreach ($rules as $rule) {
            if ($rule['on'] !== $on) {
                continue;
            }
            $results = self::results($rule, $value, $variables);
            $failed = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
            if ($failed === []) {
                continue;
            }
            $failures[] = ['code' => $rule['code'], 'message' => strtr($rule['message'], [
                '{actual}' => $failed[0]['actual'],
                '{expected}' => $failed[0]['expected'],
                '{detail}' => implode(', ', array_map(fn (array $result): string => trim(($result['label'] ?? '').' '.$result['actual'].' de '.$result['expected']), $failed)),
            ])];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $value
     * @param  array<string, string>  $variables
     * @return list<array{label: string|null, actual: string, expected: string, ok: bool}>
     */
    public static function results(array $rule, array $value, array $variables): array
    {
        $scope = new Scope($value, [], $variables);

        return array_values(array_map(function (array $check) use ($scope, $variables): array {
            $actual = match (true) {
                isset($check['count']) => BlockLayout::count($scope->collect($check['count'])),
                isset($check['filled']) => count(array_filter($scope->collect($check['filled']), fn (mixed $item): bool => (is_string($item) || is_int($item) || is_float($item)) && trim((string) $item) !== '')),
                default => array_sum(array_map(Numbers::parse(...), $scope->collect($check['sum']))),
            };
            $comparison = isset($check['equals']) ? 'equals' : (isset($check['max']) ? 'max' : 'min');
            $target = $check[$comparison];
            $expected = match (true) {
                is_array($target) && isset($target['count']) => BlockLayout::count($scope->collect($target['count'])),
                is_array($target) => Numbers::parse($variables[$target['variable']] ?? ''),
                default => $target,
            };
            $unknown = is_array($target) && isset($target['variable']) && trim($variables[$target['variable']] ?? '') === '';
            $ok = $unknown || match ($comparison) {
                'equals' => abs($actual - $expected) <= 0.001,
                'max' => $actual <= $expected + 0.001,
                default => $actual >= $expected - 0.001,
            };

            $label = $check['label'] ?? null;

            return ['label' => is_string($label) ? $label : null, 'actual' => Numbers::plain($actual), 'expected' => Numbers::plain($expected), 'ok' => $ok];
        }, $rule['checks']));
    }
}
