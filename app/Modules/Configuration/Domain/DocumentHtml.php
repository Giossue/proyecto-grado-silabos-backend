<?php

namespace App\Modules\Configuration\Domain;

use DOMDocument;
use DOMElement;
use DOMNode;

final class DocumentHtml
{
    private const INLINE_MARKS = ['strong' => 'bold', 'b' => 'bold', 'em' => 'italic', 'i' => 'italic', 'u' => 'underline'];

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public static function toLines(array $document): array
    {
        $lines = [];
        foreach ($document['content'] ?? [] as $node) {
            array_push($lines, ...self::block($node));
        }

        return $lines;
    }

    /**
     * @param  string|list<string>  $html
     * @return array<string, mixed>
     */
    public static function fromHtml(string|array $html, bool $layout = false): array
    {
        $source = is_array($html) ? implode("\n", $html) : $html;
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>'.$source.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $dom->getElementsByTagName('body')->item(0);
        $content = $body instanceof DOMNode ? self::blocks($body, $layout) : [];

        return ['type' => 'doc', 'content' => $content === [] ? [['type' => 'paragraph']] : $content];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function block(array $node): array
    {
        return match ($node['type']) {
            'paragraph' => [self::paragraph($node)],
            'bulletList', 'orderedList' => self::list($node),
            'table' => self::table($node),
            'documentBlock' => [self::blockElement($node)],
            'group' => self::group($node),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function group(array $node): array
    {
        $lines = ['<section'.self::directives($node['attrs'] ?? []).'>'];
        foreach ($node['content'] ?? [] as $child) {
            array_push($lines, ...self::block($child));
        }
        $lines[] = '</section>';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private static function directives(array $attrs): string
    {
        $html = '';
        foreach (['repeat', 'when'] as $directive) {
            if (isset($attrs[$directive])) {
                $html .= ' data-'.$directive."='".htmlspecialchars(json_encode($attrs[$directive], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ENT_NOQUOTES | ENT_XML1, 'UTF-8')."'";
            }
        }
        if (isset($attrs['band'])) {
            $html .= ' data-band="'.$attrs['band'].'"';
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function blockElement(array $node): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $encode = fn (mixed $value): string => htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '<div data-block="'.htmlspecialchars((string) ($attrs['kind'] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8').'" data-props="'.$encode($attrs['props'] ?? new \stdClass).'" data-value="'.$encode($attrs['value'] ?? new \stdClass).'"></div>';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function paragraph(array $node): string
    {
        $align = $node['attrs']['textAlign'] ?? null;
        $style = $align !== null && $align !== 'left' ? ' style="text-align:'.$align.'"' : '';

        return '<p'.$style.self::directives($node['attrs'] ?? []).'>'.self::inline($node['content'] ?? []).'</p>';
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function inline(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $inner = match ($node['type']) {
                'text' => htmlspecialchars($node['text'], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                'hardBreak' => '<br>',
                'variable' => '<span data-variable="'.htmlspecialchars((string) $node['attrs']['id'], ENT_QUOTES | ENT_XML1).'"'
                    .(isset($node['attrs']['listStyle']) ? ' data-list="'.$node['attrs']['listStyle'].'"' : '').'></span>',
                'slot' => "<span data-slot='".htmlspecialchars(json_encode($node['attrs'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ENT_NOQUOTES | ENT_XML1, 'UTF-8')."'></span>",
                default => '',
            };
            foreach (array_reverse($node['marks'] ?? []) as $mark) {
                $inner = match ($mark['type']) {
                    'bold' => "<strong>{$inner}</strong>",
                    'italic' => "<em>{$inner}</em>",
                    'underline' => "<u>{$inner}</u>",
                    'textStyle' => self::textStyle($mark['attrs'] ?? [], $inner),
                    default => $inner,
                };
            }
            $html .= $inner;
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private static function textStyle(array $attrs, string $inner): string
    {
        $style = [];
        if (isset($attrs['color'])) {
            $style[] = 'color:'.$attrs['color'];
        }
        if (isset($attrs['fontFamily'])) {
            $style[] = 'font-family:'.$attrs['fontFamily'];
        }

        return $style === [] ? $inner : '<span style="'.implode(';', $style).'">'.$inner.'</span>';
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function list(array $node): array
    {
        $tag = $node['type'] === 'orderedList' ? 'ol' : 'ul';
        $lines = ["<{$tag}".self::directives($node['attrs'] ?? []).'>'];
        foreach ($node['content'] as $item) {
            $parts = $item['content'] ?? [];
            $first = $parts[0] ?? null;
            $simple = is_array($first) && $first['type'] === 'paragraph' && ($first['attrs']['textAlign'] ?? 'left') === 'left';
            if ($simple) {
                $line = '<li>'.self::inline($first['content'] ?? []);
                $rest = array_slice($parts, 1);
            } else {
                $line = '<li>';
                $rest = $parts;
            }
            $nested = [];
            foreach ($rest as $child) {
                array_push($nested, ...self::block($child));
            }
            if ($nested === []) {
                $lines[] = $line.'</li>';
            } else {
                $lines[] = $line;
                array_push($lines, ...$nested);
                $lines[] = '</li>';
            }
        }
        $lines[] = "</{$tag}>";

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function table(array $node): array
    {
        $lines = ['<table'.self::directives($node['attrs'] ?? []).'>'];
        foreach ($node['content'] as $row) {
            $open = '<tr'.self::directives($row['attrs'] ?? []).'>';
            $cells = [];
            $multiline = false;
            foreach ($row['content'] as $cell) {
                $rendered = self::cell($cell);
                $multiline = $multiline || count($rendered) > 1;
                $cells[] = $rendered;
            }
            if (! $multiline) {
                $lines[] = $open.implode('', array_map(fn (array $parts): string => $parts[0], $cells)).'</tr>';

                continue;
            }
            $lines[] = $open;
            foreach ($cells as $parts) {
                array_push($lines, ...$parts);
            }
            $lines[] = '</tr>';
        }
        $lines[] = '</table>';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return list<string>
     */
    private static function cell(array $cell): array
    {
        $tag = $cell['type'] === 'tableHeader' ? 'th' : 'td';
        $attrs = $cell['attrs'] ?? [];
        $open = "<{$tag}";
        if (($attrs['colspan'] ?? 1) > 1) {
            $open .= ' colspan="'.$attrs['colspan'].'"';
        }
        if (($attrs['rowspan'] ?? 1) > 1) {
            $open .= ' rowspan="'.$attrs['rowspan'].'"';
        }
        if (! empty($attrs['colwidth'])) {
            $open .= ' data-width="'.implode(',', $attrs['colwidth']).'"';
        }
        if (! empty($attrs['borderStyle'])) {
            $open .= ' data-border="'.$attrs['borderStyle'].'"';
        }
        $style = [];
        if (! empty($attrs['backgroundColor'])) {
            $style[] = 'background:'.$attrs['backgroundColor'];
        }
        if (! empty($attrs['textColor'])) {
            $style[] = 'color:'.$attrs['textColor'];
        }
        if (! empty($attrs['textAlign'])) {
            $style[] = 'text-align:'.$attrs['textAlign'];
        }
        if (isset($attrs['bold'])) {
            $style[] = 'font-weight:'.($attrs['bold'] ? 'bold' : 'normal');
        }
        if (isset($attrs['italic'])) {
            $style[] = 'font-style:'.($attrs['italic'] ? 'italic' : 'normal');
        }
        if ($style !== []) {
            $open .= ' style="'.implode(';', $style).'"';
        }
        $open .= self::directives($attrs).'>';

        $content = $cell['content'] ?? [];
        $first = $content[0] ?? null;
        $plain = count($content) === 1 && is_array($first) && $first['type'] === 'paragraph' && ($first['attrs']['textAlign'] ?? 'left') === 'left';
        if ($plain) {
            return [$open.self::inline($first['content'] ?? [])."</{$tag}>"];
        }
        $lines = [$open];
        foreach ($content as $child) {
            array_push($lines, ...self::block($child));
        }
        $lines[] = "</{$tag}>";

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $buffer
     */
    private static function flushInline(array &$blocks, array &$buffer): void
    {
        $last = array_key_last($buffer);
        if ($last !== null && ($buffer[$last]['type'] ?? '') === 'text' && is_string($buffer[$last]['text'] ?? null)) {
            $buffer[$last]['text'] = rtrim($buffer[$last]['text'], "\n");
            if ($buffer[$last]['text'] === '') {
                array_pop($buffer);
            }
        }
        if ($buffer !== []) {
            $blocks[] = ['type' => 'paragraph', 'content' => $buffer];
            $buffer = [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function blocks(DOMNode $parent, bool $layout = false): array
    {
        $blocks = [];
        $buffer = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'p') {
                    self::flushInline($blocks, $buffer);
                    $content = self::inlineNodes($child, []);
                    $paragraph = ['type' => 'paragraph'];
                    if ($content !== []) {
                        $paragraph['content'] = $content;
                    }
                    $align = self::style($child)['text-align'] ?? null;
                    $attrs = [...($align !== null ? ['textAlign' => $align] : []), ...self::directiveAttrs($child)];
                    if ($attrs !== []) {
                        $paragraph['attrs'] = $attrs;
                    }
                    $blocks[] = $paragraph;
                } elseif ($tag === 'section' && $layout) {
                    self::flushInline($blocks, $buffer);
                    $group = ['type' => 'group', 'content' => self::blocks($child, true)];
                    $attrs = self::directiveAttrs($child);
                    if ($attrs !== []) {
                        $group['attrs'] = $attrs;
                    }
                    $blocks[] = $group;
                } elseif ($tag === 'ul' || $tag === 'ol') {
                    self::flushInline($blocks, $buffer);
                    $blocks[] = self::withDirectives(self::listNode($child), $child);
                } elseif ($tag === 'table') {
                    self::flushInline($blocks, $buffer);
                    $blocks[] = self::withDirectives(self::tableNode($child), $child);
                } elseif ($tag === 'div' && $child->hasAttribute('data-block')) {
                    self::flushInline($blocks, $buffer);
                    $props = json_decode($child->getAttribute('data-props') ?: '{}', true);
                    $value = json_decode($child->getAttribute('data-value') ?: '{}', true);
                    $blocks[] = ['type' => 'documentBlock', 'attrs' => ['kind' => $child->getAttribute('data-block'), 'props' => is_array($props) ? $props : [], 'value' => is_array($value) ? $value : []]];
                } else {
                    array_push($buffer, ...self::inlineNodes($child, [], true));
                }
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->nodeValue ?? '';
                if ($buffer === []) {
                    $text = ltrim($text, "\n");
                }
                if (trim($text) !== '' || $buffer !== []) {
                    array_push($buffer, ...self::inlineNodes($text === ($child->nodeValue ?? '') ? $child : $child->ownerDocument->createTextNode($text), []));
                }
            }
        }
        self::flushInline($blocks, $buffer);

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private static function inlineNodes(DOMNode $node, array $marks, bool $self = false): array
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue ?? '';

            return $text === '' ? [] : [self::withMarks(['type' => 'text', 'text' => $text], $marks)];
        }
        if (! $node instanceof DOMElement && ! $node instanceof DOMDocument) {
            return [];
        }
        $out = [];
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if ($tag === 'br') {
                return [['type' => 'hardBreak']];
            }
            if ($tag === 'span' && $node->hasAttribute('data-slot')) {
                $attrs = json_decode($node->getAttribute('data-slot'), true);

                return [self::withMarks(['type' => 'slot', 'attrs' => is_array($attrs) ? $attrs : []], $marks)];
            }
            if ($tag === 'span' && $node->hasAttribute('data-variable')) {
                $variable = ['type' => 'variable', 'attrs' => ['id' => $node->getAttribute('data-variable')]];
                if ($node->hasAttribute('data-list')) {
                    $variable['attrs']['listStyle'] = $node->getAttribute('data-list');
                }

                return [self::withMarks($variable, $marks)];
            }
            if (isset(self::INLINE_MARKS[$tag])) {
                $marks[] = ['type' => self::INLINE_MARKS[$tag]];
            } elseif ($tag === 'span') {
                $style = self::style($node);
                $attrs = array_filter(['color' => $style['color'] ?? null, 'fontFamily' => $style['font-family'] ?? null]);
                if ($attrs !== []) {
                    $marks[] = ['type' => 'textStyle', 'attrs' => $attrs];
                }
            }
        }
        foreach ($node->childNodes as $child) {
            array_push($out, ...self::inlineNodes($child, $marks));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private static function withMarks(array $node, array $marks): array
    {
        if ($marks !== []) {
            $node['marks'] = $marks;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private static function listNode(DOMElement $list): array
    {
        $items = [];
        foreach ($list->childNodes as $li) {
            if (! $li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }
            $content = self::blocks($li);
            $items[] = ['type' => 'listItem', 'content' => $content === [] ? [['type' => 'paragraph']] : $content];
        }

        return ['type' => strtolower($list->tagName) === 'ol' ? 'orderedList' : 'bulletList', 'content' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tableNode(DOMElement $table): array
    {
        $rows = [];
        $collect = function (DOMNode $parent) use (&$collect, &$rows): void {
            foreach ($parent->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'tr') {
                    $rows[] = self::withDirectives(self::rowNode($child), $child);
                } elseif (in_array($tag, ['tbody', 'thead', 'tfoot'], true)) {
                    $collect($child);
                }
            }
        };
        $collect($table);

        return ['type' => 'table', 'content' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowNode(DOMElement $row): array
    {
        $cells = [];
        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                continue;
            }
            $style = self::style($cell);
            $attrs = [
                'colspan' => $cell->hasAttribute('colspan') ? (int) $cell->getAttribute('colspan') : 1,
                'rowspan' => $cell->hasAttribute('rowspan') ? (int) $cell->getAttribute('rowspan') : 1,
            ];
            if ($cell->hasAttribute('data-width')) {
                $attrs['colwidth'] = array_map('intval', explode(',', $cell->getAttribute('data-width')));
            }
            if ($cell->hasAttribute('data-border')) {
                $attrs['borderStyle'] = $cell->getAttribute('data-border');
            }
            $map = ['background' => 'backgroundColor', 'color' => 'textColor', 'text-align' => 'textAlign'];
            foreach ($map as $css => $attribute) {
                if (isset($style[$css])) {
                    $attrs[$attribute] = $style[$css];
                }
            }
            if (isset($style['font-weight'])) {
                $attrs['bold'] = $style['font-weight'] === 'bold';
            }
            if (isset($style['font-style'])) {
                $attrs['italic'] = $style['font-style'] === 'italic';
            }
            $content = self::blocks($cell);
            $cells[] = [
                'type' => strtolower($cell->tagName) === 'th' ? 'tableHeader' : 'tableCell',
                'attrs' => [...$attrs, ...self::directiveAttrs($cell)],
                'content' => $content === [] ? [['type' => 'paragraph']] : $content,
            ];
        }

        return ['type' => 'tableRow', 'content' => $cells];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function withDirectives(array $node, DOMElement $element): array
    {
        $attrs = self::directiveAttrs($element);
        if ($attrs !== []) {
            $node['attrs'] = [...($node['attrs'] ?? []), ...$attrs];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private static function directiveAttrs(DOMElement $element): array
    {
        $attrs = [];
        foreach (['repeat', 'when'] as $directive) {
            if ($element->hasAttribute('data-'.$directive)) {
                $decoded = json_decode($element->getAttribute('data-'.$directive), true);
                $attrs[$directive] = is_array($decoded) ? $decoded : $element->getAttribute('data-'.$directive);
            }
        }
        if ($element->hasAttribute('data-band')) {
            $attrs['band'] = $element->getAttribute('data-band');
        }

        return $attrs;
    }

    /**
     * @return array<string, string>
     */
    private static function style(DOMElement $element): array
    {
        $style = [];
        foreach (explode(';', $element->getAttribute('style')) as $declaration) {
            [$name, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            if ($name !== null && $value !== null && trim($name) !== '') {
                $style[strtolower(trim($name))] = trim($value);
            }
        }

        return $style;
    }
}
