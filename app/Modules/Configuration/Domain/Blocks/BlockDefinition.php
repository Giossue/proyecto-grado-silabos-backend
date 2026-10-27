<?php

namespace App\Modules\Configuration\Domain\Blocks;

use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;

final class BlockDefinition
{
    public const KIND = '/^[a-z][a-z0-9_]{0,40}$/';

    /**
     * @param  list<string>  $variables
     * @return array<string, mixed>
     */
    public static function define(string $kind, mixed $raw, array $variables): array
    {
        $where = "blocks.{$kind}";
        if (preg_match(self::KIND, $kind) !== 1) {
            BlockDefinitionError::fail("{$where}: el nombre del bloque usa minúsculas, números y _.");
        }
        if (! is_array($raw) || array_is_list($raw)) {
            BlockDefinitionError::fail("{$where}: escriba la definición del bloque como objeto.");
        }
        $unknown = array_diff(array_keys($raw), ['label', 'props', 'value', 'rules', 'layout']);
        if ($unknown !== []) {
            BlockDefinitionError::fail("{$where}: clave desconocida «".implode('», «', $unknown).'».');
        }
        if (! is_string($raw['label'] ?? null) || trim($raw['label']) === '' || mb_strlen($raw['label']) > 120) {
            BlockDefinitionError::fail("{$where}.label: escriba el nombre del bloque (hasta 120 caracteres).");
        }
        $props = ValueSchema::define($raw['props'] ?? null, "{$where}.props", true);
        $value = ValueSchema::define($raw['value'] ?? null, "{$where}.value");

        return [
            'label' => $raw['label'],
            'props' => $props,
            'value' => $value,
            'rules' => BlockRules::define($raw['rules'] ?? null, "{$where}.rules", $value, $variables),
            'layout' => BlockLayout::define($raw['layout'] ?? null, "{$where}.layout", $value, $props, $variables),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attrs
     * @param  array<string, string>  $variables
     * @return array{kind: string, props: array<string, mixed>, value: array<string, mixed>}
     */
    public static function normalize(string $kind, array $definition, array $attrs, array $variables = []): array
    {
        $props = ValueSchema::props($definition['props'], $attrs['props'] ?? null);
        $value = ValueSchema::normalize($definition['value'], $attrs['value'] ?? null, $props);
        $failure = BlockRules::failures($definition['rules'], $value, $variables, 'save')[0] ?? null;
        if ($failure !== null) {
            TemplateDocument::fail($failure['message']);
        }

        return ['kind' => $kind, 'props' => $props, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attrs
     * @param  array<string, string>  $variables
     * @return list<array<string, mixed>>
     */
    public static function expand(array $definition, array $attrs, array $variables): array
    {
        return BlockLayout::expand($definition['layout'], self::scope($attrs, $variables), ['type' => 'object', 'properties' => $definition['value']]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attrs
     */
    public static function filledText(array $definition, array $attrs): string
    {
        $parts = [];
        BlockLayout::expand($definition['layout'], self::scope($attrs, []), ['type' => 'object', 'properties' => $definition['value']], function (string $text) use (&$parts): void {
            if (trim($text) !== '') {
                $parts[] = trim($text);
            }
        });

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attrs
     * @param  array<string, string>  $variables
     */
    public static function plainText(array $definition, array $attrs, array $variables): string
    {
        return FreeText::plainText(['type' => 'doc', 'content' => self::expand($definition, $attrs, $variables)]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, string>  $variables
     */
    private static function scope(array $attrs, array $variables): Scope
    {
        return new Scope(
            is_array($attrs['value'] ?? null) ? $attrs['value'] : [],
            is_array($attrs['props'] ?? null) ? $attrs['props'] : [],
            $variables,
        );
    }
}
