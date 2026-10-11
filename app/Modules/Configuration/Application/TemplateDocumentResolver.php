<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\FreeText;

final class TemplateDocumentResolver
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    public static function resolve(array $document, array $fields, array $variables): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = ($field['hidden'] ?? false) === true ? [...$field, 'value' => null] : $field;
        }
        $visit = function (array $node) use (&$visit, $byKey, $variables): array {
            $type = $node['type'];
            $attrs = $node['attrs'] ?? [];
            if ($type === 'documentBlock') {
                return DocumentBlock::expand($node);
            }
            if (in_array($type, ['variable', 'field'], true)) {
                $value = '';
                if ($type === 'variable') {
                    $value = $variables[$attrs['id']] ?? '';
                    if (($attrs['listStyle'] ?? null) !== null) {
                        $items = array_map(fn (string $line): string => (string) preg_replace('/^\d+\.\s+/u', '', $line), preg_split('/\R/u', $value) ?: []);

                        return [self::listNode($node, $items)];
                    }
                } else {
                    $field = $byKey[$attrs['key']] ?? [];
                    if (($field['type'] ?? $attrs['kind'] ?? null) === 'texto_libre') {
                        return FreeText::blocks($field['value'] ?? null);
                    }
                    if (($attrs['listStyle'] ?? null) !== null) {
                        return [self::listNode($node, preg_split('/\R/u', self::display($field['value'] ?? null)) ?: [])];
                    }
                    $value = self::display($field['value'] ?? null);
                    if (($attrs['choice'] ?? null) !== null) {
                        $value = $value === $attrs['choice'] ? 'X' : '';
                    }
                }

                return [['type' => 'text', 'text' => $value, 'marks' => $node['marks'] ?? []]];
            }
            if (isset($node['content'])) {
                $children = [];
                foreach ($node['content'] as $child) {
                    $children = [...$children, ...$visit($child)];
                }
                $node['content'] = $children;
                if (in_array($type, ['bulletList', 'orderedList'], true)) {
                    $node['content'] = self::flattenItems($children);
                }
                if ($type === 'paragraph') {
                    $parts = [];
                    $inline = [];
                    foreach ($children as $child) {
                        if (in_array($child['type'], ['bulletList', 'orderedList', 'table', 'paragraph'], true)) {
                            if ($inline !== []) {
                                $parts[] = [...$node, 'content' => $inline];
                                $inline = [];
                            }
                            $parts[] = $child;
                        } else {
                            $inline[] = $child;
                        }
                    }
                    if ($parts !== []) {
                        if ($inline !== []) {
                            $parts[] = [...$node, 'content' => $inline];
                        }

                        return $parts;
                    }
                }
            }

            return [$node];
        };

        return $visit($document)[0];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function flattenItems(array $items): array
    {
        $flat = [];
        foreach ($items as $item) {
            $content = $item['content'] ?? [];
            if (($item['type'] ?? null) === 'listItem' && count($content) === 1
                && in_array($content[0]['type'] ?? null, ['bulletList', 'orderedList'], true)) {
                array_push($flat, ...($content[0]['content'] ?? []));

                continue;
            }
            $flat[] = $item;
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    private static function listNode(array $node, array $items): array
    {
        return ['type' => $node['attrs']['listStyle'] === 'number' ? 'orderedList' : 'bulletList', 'content' => array_values(array_map(
            fn ($text) => ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [[
                'type' => 'text', 'text' => $text, 'marks' => $node['marks'] ?? [],
            ]]]]],
            array_filter($items, fn ($item) => trim($item) !== ''),
        ))];
    }

    public static function display(mixed $value): string
    {
        if (FreeText::isDocument($value)) {
            /** @var array<string, mixed> $value */
            return FreeText::plainText($value);
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return implode(' · ', array_map(self::display(...), $value));
        }

        return '';
    }
}
