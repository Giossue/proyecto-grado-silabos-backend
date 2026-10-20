<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\Blocks\BlockDefinition;
use App\Modules\Configuration\Domain\Blocks\BlockRules;

final class DocumentBlock
{
    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, array<string, mixed>>|null  $blocks
     * @return array{kind: string, props: array<string, mixed>, value: array<string, mixed>}
     */
    public static function normalize(array $attrs, ?array $blocks = null): array
    {
        $kind = $attrs['kind'] ?? null;
        $definition = is_string($kind) ? ($blocks ?? TemplateContext::active()->blocks())[$kind] ?? null : null;
        if ($definition === null) {
            TemplateDocument::fail('El bloque no es de un tipo permitido.');
        }

        return BlockDefinition::normalize($kind, $definition, $attrs);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $variables
     * @return list<array<string, mixed>>
     */
    public static function expand(array $node, array $variables = []): array
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $definition = self::definition($attrs);

        return $definition === null ? [] : BlockDefinition::expand($definition, $attrs, $variables);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function plainText(array $attrs): string
    {
        $definition = self::definition($attrs);

        return $definition === null ? '' : BlockDefinition::filledText($definition, $attrs);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $variables
     * @return list<array{code: string, message: string}>
     */
    public static function failures(array $node, array $variables, string $on = 'submit'): array
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $definition = self::definition($attrs);

        return $definition === null ? [] : BlockRules::failures($definition['rules'], is_array($attrs['value'] ?? null) ? $attrs['value'] : [], $variables, $on);
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $document
     */
    public static function sameStructure(array $template, array $document): bool
    {
        return self::skeleton($template) === self::skeleton($document);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{kind: mixed, props: mixed}>
     */
    private static function skeleton(array $document): array
    {
        $blocks = [];
        foreach (is_array($document['content'] ?? null) ? $document['content'] : [] as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'documentBlock') {
                $blocks[] = ['kind' => $node['attrs']['kind'] ?? null, 'props' => $node['attrs']['props'] ?? null];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>|null
     */
    private static function definition(array $attrs): ?array
    {
        $kind = $attrs['kind'] ?? null;

        return is_string($kind) ? TemplateContext::active()->block($kind) : null;
    }
}
