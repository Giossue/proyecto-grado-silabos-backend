<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\Data\AcademicSourceOcrPage;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class OpenParserDocumentConverter
{
    /**
     * @param  callable(AcademicSourceOcrPage, string, string): ?string  $imageResolver
     * @return array{schemaVersion: int, type: string, content: list<array<string, mixed>>}
     */
    public function convert(AcademicSourceOcrResult $result, callable $imageResolver): array
    {
        $content = [];
        foreach ($result->pages as $page) {
            $order = 0;
            $parts = preg_split(
                '~(<table\b[^>]*>.*?</table>|!\[[^\]]*\]\([^\)]+\))~isu',
                $page->markdown,
                -1,
                PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            foreach ($parts as $part) {
                if (preg_match('~^<table\b~i', trim($part)) === 1) {
                    $table = $this->table(trim($part), $page->index, $order++);
                    if ($table !== null) {
                        $content[] = $table;
                    }

                    continue;
                }
                if (preg_match('~^!\[([^\]]*)\]\(([^\s\)]+)(?:\s+"[^"]*")?\)$~u', trim($part), $match) === 1) {
                    $imageId = ltrim($match[2], './');
                    $objectId = $imageResolver($page, $imageId, $page->images[$imageId] ?? $match[2]);
                    if (is_string($objectId)) {
                        $content[] = [
                            'type' => 'sourceImage',
                            'attrs' => [
                                'objectId' => $objectId,
                                'alt' => trim($match[1]),
                                'title' => basename($imageId),
                                'sourcePage' => $page->index,
                                'sourceOrder' => $order++,
                            ],
                        ];
                    }

                    continue;
                }
                foreach ($this->textBlocks($part, $page->index, $order) as $node) {
                    $content[] = $node;
                }
            }
        }

        return ['schemaVersion' => 1, 'type' => 'doc', 'content' => $content];
    }

    /** @return list<array<string, mixed>> */
    private function textBlocks(string $markdown, int $page, int &$order): array
    {
        $nodes = $this->emptyNodes();
        $paragraph = $this->emptyStrings();
        $list = $this->emptyStrings();
        $listType = null;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $nodes = $this->appendParagraph($nodes, $paragraph, $page, $order);
                $nodes = $this->appendList($nodes, $list, $listType, $page, $order);
                $paragraph = $this->emptyStrings();
                $list = $this->emptyStrings();
                $listType = null;

                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $trimmed, $match) === 1) {
                $nodes = $this->appendParagraph($nodes, $paragraph, $page, $order);
                $nodes = $this->appendList($nodes, $list, $listType, $page, $order);
                $paragraph = $this->emptyStrings();
                $list = $this->emptyStrings();
                $listType = null;
                $nodes[] = $this->textNode('heading', $this->plain($match[2]), $page, $order++, ['level' => strlen($match[1])]);

                continue;
            }
            if (preg_match('/^[-*+]\s+(.+)$/u', $trimmed, $match) === 1 || preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $match) === 1) {
                $nodes = $this->appendParagraph($nodes, $paragraph, $page, $order);
                $paragraph = $this->emptyStrings();
                $currentType = preg_match('/^\d/u', $trimmed) === 1 ? 'ordered' : 'bullet';
                if ($listType !== null && $listType !== $currentType) {
                    $nodes = $this->appendList($nodes, $list, $listType, $page, $order);
                    $list = $this->emptyStrings();
                }
                $listType = $currentType;
                $list[] = $this->plain($match[1]);

                continue;
            }
            if (preg_match('/^---+$/', $trimmed) === 1) {
                $nodes = $this->appendParagraph($nodes, $paragraph, $page, $order);
                $nodes = $this->appendList($nodes, $list, $listType, $page, $order);
                $paragraph = $this->emptyStrings();
                $list = $this->emptyStrings();
                $listType = null;
                $nodes[] = ['type' => 'horizontalRule', 'attrs' => ['sourcePage' => $page, 'sourceOrder' => $order++]];

                continue;
            }
            $paragraph[] = $this->plain($trimmed);
        }
        $nodes = $this->appendParagraph($nodes, $paragraph, $page, $order);

        return $this->appendList($nodes, $list, $listType, $page, $order);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function textNode(string $type, string $text, ?int $page = null, ?int $order = null, array $attrs = []): array
    {
        if ($page !== null) {
            $attrs['sourcePage'] = $page;
        }
        if ($order !== null) {
            $attrs['sourceOrder'] = $order;
        }

        return ['type' => $type, 'attrs' => $attrs, 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return array<string, mixed>|null */
    private function table(string $html, int $page, int $order): ?array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//table//tr') ?: [] as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) ?: [] as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');
                $cells[] = [
                    'type' => strtolower($cell->tagName) === 'th' ? 'tableHeader' : 'tableCell',
                    'attrs' => [
                        'colspan' => max(1, (int) ($cell->getAttribute('colspan') ?: 1)),
                        'rowspan' => max(1, (int) ($cell->getAttribute('rowspan') ?: 1)),
                        'colwidth' => null,
                    ],
                    'content' => [$this->textNode('paragraph', $text)],
                ];
            }
            if ($cells !== []) {
                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return $rows === [] ? null : [
            'type' => 'table',
            'attrs' => ['sourcePage' => $page, 'sourceOrder' => $order],
            'content' => $rows,
        ];
    }

    private function plain(string $value): string
    {
        $value = preg_replace('/`([^`]+)`/u', '$1', $value) ?? $value;
        $value = preg_replace('/(?:\*\*|__)(.+?)(?:\*\*|__)/u', '$1', $value) ?? $value;
        $value = preg_replace('/(?:\*|_)(.+?)(?:\*|_)/u', '$1', $value) ?? $value;
        $value = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $value) ?? $value;

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $paragraph
     * @return list<array<string, mixed>>
     */
    private function appendParagraph(array $nodes, array $paragraph, int $page, int &$order): array
    {
        $text = trim(implode(' ', $paragraph));
        if ($text !== '') {
            $nodes[] = $this->textNode('paragraph', $text, $page, $order++);
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $items
     * @return list<array<string, mixed>>
     */
    private function appendList(array $nodes, array $items, ?string $type, int $page, int &$order): array
    {
        if ($items === []) {
            return $nodes;
        }
        $nodes[] = [
            'type' => $type === 'ordered' ? 'orderedList' : 'bulletList',
            'attrs' => ['sourcePage' => $page, 'sourceOrder' => $order++],
            'content' => array_map(
                fn (string $item): array => ['type' => 'listItem', 'content' => [$this->textNode('paragraph', $item)]],
                $items,
            ),
        ];

        return $nodes;
    }

    /** @return list<string> */
    private function emptyStrings(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    private function emptyNodes(): array
    {
        return [];
    }
}
