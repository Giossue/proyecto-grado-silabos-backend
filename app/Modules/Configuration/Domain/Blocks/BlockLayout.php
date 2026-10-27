<?php

namespace App\Modules\Configuration\Domain\Blocks;

use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\TemplateDocument;
use Illuminate\Validation\ValidationException;

final class BlockLayout
{
    /**
     * @param  array<string, array<string, mixed>>  $value
     * @param  array<string, array<string, mixed>>  $props
     * @param  list<string>  $variables
     * @return list<array<string, mixed>>
     */
    public static function define(mixed $raw, string $where, array $value, array $props, array $variables): array
    {
        $document = match (true) {
            is_string($raw) || (is_array($raw) && array_is_list($raw) && $raw !== [] && is_string($raw[0])) => DocumentHtml::fromHtml($raw, true),
            is_array($raw) && array_is_list($raw) => ['type' => 'doc', 'content' => $raw],
            default => BlockDefinitionError::fail("{$where}: el diseño es una lista de líneas HTML o de nodos."),
        };
        try {
            $normalized = TemplateDocument::normalize($document, $variables, new LayoutRules($where));
        } catch (ValidationException $exception) {
            BlockDefinitionError::fail("{$where}: ".collect($exception->errors())->flatten()->first());
        }
        self::check($normalized['content'], ['type' => 'object', 'properties' => $value], $props, [], $where);

        return $normalized['content'];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $props
     * @param  array<string, true>  $aliases
     */
    private static function check(array $nodes, array $schema, array $props, array $aliases, string $where): void
    {
        foreach ($nodes as $node) {
            $attrs = $node['attrs'] ?? [];
            $inner = $schema;
            $innerAliases = $aliases;
            if (isset($attrs['repeat'])) {
                $repeat = $attrs['repeat'];
                $list = self::schemaAt($schema, $repeat['value']);
                if (($list['type'] ?? null) !== 'array') {
                    BlockDefinitionError::fail("{$where}: «{$repeat['value']}» no es una lista del valor.");
                }
                if ($list['minItems'] < $list['maxItems'] && $node['type'] !== 'tableRow') {
                    BlockDefinitionError::fail("{$where}: la lista «{$repeat['value']}» cambia de tamaño y por eso se repite en una fila de tabla (tr).");
                }
                $inner = $list['items'];
                if (isset($repeat['as'])) {
                    $innerAliases[$repeat['as']] = true;
                }
            }
            if ($node['type'] === 'slot') {
                self::checkSlot($attrs, $schema, $props, $aliases, $where);
            }
            if (isset($node['content'])) {
                self::check($node['content'], $inner, $props, $innerAliases, $where);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $props
     * @param  array<string, true>  $aliases
     */
    private static function checkSlot(array $attrs, array $schema, array $props, array $aliases, string $where): void
    {
        foreach (Scope::segments((string) ($attrs['value'] ?? $attrs['sum'] ?? $attrs['count'] ?? '')) as $segment) {
            if (str_starts_with($segment, '{') && ! isset($aliases[explode('.', substr($segment, 1, -1))[0]])) {
                BlockDefinitionError::fail("{$where}: «{$segment}» usa un nombre que no se definió con «as».");
            }
        }
        if (isset($attrs['value'])) {
            $target = self::schemaAt($schema, $attrs['value']);
            if ($target === null || in_array($target['type'], ['object', 'array'], true)) {
                BlockDefinitionError::fail("{$where}: «{$attrs['value']}» no es un dato del valor del bloque.");
            }
            if (isset($attrs['option']) && ValueSchema::optionTitle($target, $attrs['option']) === '') {
                BlockDefinitionError::fail("{$where}: «{$attrs['option']}» no es una opción de «{$attrs['value']}».");
            }
        }
        if (isset($attrs['prop']) && ! isset($props[$attrs['prop']])) {
            BlockDefinitionError::fail("{$where}: «{$attrs['prop']}» no es una propiedad del bloque.");
        }
        if (isset($attrs['item']) && ! isset($aliases[explode('.', $attrs['item'])[0]])) {
            BlockDefinitionError::fail("{$where}: «{$attrs['item']}» usa un nombre que no se definió con «as».");
        }
        foreach (['sum', 'count'] as $aggregate) {
            if (isset($attrs[$aggregate]) && self::schemaAt($schema, $attrs[$aggregate]) === null) {
                BlockDefinitionError::fail("{$where}: «{$attrs[$aggregate]}» no existe en el valor del bloque.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    public static function schemaAt(array $schema, string $path): ?array
    {
        return $schema['type'] === 'object' ? ValueSchema::at($schema['properties'], $path) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $layout
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    public static function expand(array $layout, Scope $scope, array $schema, ?\Closure $collect = null): array
    {
        return self::nodes($layout, $scope, $schema, $collect, 'b');
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    private static function nodes(array $nodes, Scope $scope, array $schema, ?\Closure $collect, string $path): array
    {
        $out = [];
        foreach ($nodes as $position => $node) {
            $id = "{$path}.{$position}";
            $attrs = $node['attrs'] ?? [];
            if (isset($attrs['when']) && ! Condition::holds($attrs['when'], $scope)) {
                continue;
            }
            if (! isset($attrs['repeat'])) {
                array_push($out, ...self::node($node, $scope, $schema, $collect, $id));

                continue;
            }
            $repeat = $attrs['repeat'];
            $items = isset($repeat['value']) ? $scope->value($repeat['value']) : [];
            $items = is_array($items) && array_is_list($items) ? $items : [];
            while (count($items) < ($repeat['at_least'] ?? 0)) {
                $items[] = [];
            }
            $itemSchema = isset($repeat['value']) ? (self::schemaAt($schema, $repeat['value'])['items'] ?? $schema) : $schema;
            foreach ($items as $index => $item) {
                $child = isset($repeat['value']) ? $scope->child(is_array($item) ? $item : [], $index + 1) : $scope;
                if (isset($repeat['as'])) {
                    $child = $child->alias($repeat['as'], $item, $index + 1);
                }
                array_push($out, ...self::node($node, $child, $itemSchema, $collect, $id));
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    private static function node(array $node, Scope $scope, array $schema, ?\Closure $collect, string $id): array
    {
        if ($node['type'] === 'group') {
            return self::nodes($node['content'] ?? [], $scope, $schema, $collect, $id);
        }
        if ($node['type'] === 'slot') {
            $text = self::slotText($node['attrs'], $scope, $schema);
            if ($collect !== null && isset($node['attrs']['value'])) {
                $collect($text);
            }

            return $text === '' ? [] : [['type' => 'text', 'text' => $text] + (isset($node['marks']) ? ['marks' => $node['marks']] : [])];
        }
        $band = $node['attrs']['band'] ?? null;
        if (isset($node['attrs'])) {
            $node['attrs'] = array_diff_key($node['attrs'], array_flip(LayoutRules::DIRECTIVES));
            if ($node['attrs'] === [] && in_array($node['type'], ['table', 'tableRow'], true)) {
                unset($node['attrs']);
            }
        }
        if (isset($node['content'])) {
            $node['content'] = self::nodes($node['content'], $scope, $schema, $collect, $id);
        }
        if ($band !== null && $scope->counters->next("band:{$id}") % 2 === 1) {
            $node['content'] = array_map(function (array $cell) use ($band): array {
                if (($cell['attrs']['backgroundColor'] ?? null) === null) {
                    $cell['attrs']['backgroundColor'] = $band;
                }

                return $cell;
            }, $node['content'] ?? []);
        }

        return [$node];
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $schema
     */
    public static function slotText(array $attrs, Scope $scope, array $schema): string
    {
        if (isset($attrs['value'])) {
            $value = $scope->value($attrs['value']);
            if (isset($attrs['option'])) {
                return $value === $attrs['option'] ? ($attrs['mark'] ?? 'X') : '';
            }
            $target = self::schemaAt($schema, $attrs['value']);
            if ($target !== null && ValueSchema::isChoice($target)) {
                return ValueSchema::optionTitle($target, $value);
            }

            return is_scalar($value) ? (string) $value : '';
        }
        if (isset($attrs['prop'])) {
            $value = $scope->prop($attrs['prop']);

            return is_scalar($value) ? (string) $value : '';
        }
        if (isset($attrs['item'])) {
            $value = $scope->aliased($attrs['item']);

            return is_scalar($value) ? (string) $value : '';
        }
        if (isset($attrs['counter'])) {
            return (string) $scope->counters->next("counter:{$attrs['counter']}");
        }
        if (isset($attrs['sum'])) {
            return Numbers::total(array_sum(array_map(Numbers::parse(...), $scope->collect($attrs['sum']))));
        }
        if (isset($attrs['count'])) {
            return (string) self::count($scope->collect($attrs['count']));
        }

        return (string) ($scope->index ?? '');
    }

    /**
     * @param  list<mixed>  $values
     */
    public static function count(array $values): int
    {
        return array_sum(array_map(fn (mixed $value): int => is_array($value) && array_is_list($value) ? count($value) : 1, $values));
    }
}
