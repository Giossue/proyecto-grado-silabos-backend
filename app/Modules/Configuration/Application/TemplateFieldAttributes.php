<?php

namespace App\Modules\Configuration\Application;

final class TemplateFieldAttributes
{
    private const STORED = ['key', 'choice', 'listStyle'];

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public static function store(array $configuration): array
    {
        if (! is_array($configuration['document'] ?? null)) {
            return $configuration;
        }
        $configuration['document'] = self::map($configuration['document'], function (array $attrs): array {
            return array_intersect_key($attrs, array_flip(self::STORED));
        });

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function map(array $node, callable $attributes): array
    {
        if (($node['type'] ?? null) === 'field' && is_array($node['attrs'] ?? null)) {
            $node['attrs'] = $attributes($node['attrs']);
        }
        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(
                fn (mixed $child): mixed => is_array($child) ? self::map($child, $attributes) : $child,
                $node['content'],
            );
        }

        return $node;
    }
}
