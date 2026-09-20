<?php

namespace App\Modules\AiAssistance\Application;

final class StructuredSourceFragmenter
{
    /**
     * @param  array<string, mixed>|null  $document
     * @return list<array{heading_path: list<string>, title: string|null, content: string, page_start: int|null, page_end: int|null, source_blocks: list<array<string, mixed>>}>
     */
    public function fragment(?array $document, int $maxCharacters = 4000): array
    {
        $nodes = is_array($document['content'] ?? null) ? $document['content'] : [];
        $headings = $this->emptyHeadingMap();
        $title = null;
        $current = '';
        $blocks = $this->emptyBlocks();
        $pages = $this->emptyIntegers();
        $result = $this->emptyFragments();

        foreach ($nodes as $position => $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = is_string($node['type'] ?? null) ? $node['type'] : 'unknown';
            $text = trim($this->text($node));
            if ($type === 'heading' && $text !== '') {
                $result = $this->append($result, $headings, $title, $current, $pages, $blocks);
                $current = '';
                $pages = $this->emptyIntegers();
                $blocks = $this->emptyBlocks();
                $level = min(6, max(1, (int) ($node['attrs']['level'] ?? 2)));
                $headings = array_slice($headings, 0, $level - 1);
                $headings[$level - 1] = mb_substr($text, 0, 180);
                $title = mb_substr($text, 0, 180);

                continue;
            }
            if ($text === '') {
                continue;
            }
            $candidate = $current === '' ? $text : $current."\n\n".$text;
            if ($current !== '' && mb_strlen($candidate) > max(500, $maxCharacters)) {
                $result = $this->append($result, $headings, $title, $current, $pages, $blocks);
                $current = '';
                $pages = $this->emptyIntegers();
                $blocks = $this->emptyBlocks();
            }
            $current = $current === '' ? $text : $current."\n\n".$text;
            $page = isset($node['attrs']['sourcePage']) ? (int) $node['attrs']['sourcePage'] : null;
            if ($page !== null) {
                $pages[] = $page;
            }
            $blocks[] = array_filter([
                'position' => $position,
                'type' => $type,
                'page' => $page,
                'order' => isset($node['attrs']['sourceOrder']) ? (int) $node['attrs']['sourceOrder'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $this->append($result, $headings, $title, $current, $pages, $blocks);
    }

    /**
     * @param  list<array{heading_path: list<string>, title: string|null, content: string, page_start: int|null, page_end: int|null, source_blocks: list<array<string, mixed>>}>  $result
     * @param  array<int, string>  $headings
     * @param  list<int>  $pages
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{heading_path: list<string>, title: string|null, content: string, page_start: int|null, page_end: int|null, source_blocks: list<array<string, mixed>>}>
     */
    private function append(array $result, array $headings, ?string $title, string $current, array $pages, array $blocks): array
    {
        $text = trim($current);
        if ($text === '') {
            return $result;
        }
        $result[] = [
            'heading_path' => array_values($headings),
            'title' => $title,
            'content' => $text,
            'page_start' => $pages === [] ? null : min($pages),
            'page_end' => $pages === [] ? null : max($pages),
            'source_blocks' => $blocks,
        ];

        return $result;
    }

    /** @param array<string, mixed> $node */
    private function text(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if (($node['type'] ?? null) === 'sourceImage') {
            return trim((string) (($node['attrs']['alt'] ?? null) ?: ($node['attrs']['title'] ?? '')));
        }
        $separator = in_array($node['type'] ?? null, ['table', 'tableRow'], true) ? "\n" : ' ';
        $parts = $this->emptyStrings();
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $parts[] = $this->text($child);
            }
        }

        return implode($separator, array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
    }

    /** @return list<string> */
    private function emptyStrings(): array
    {
        return [];
    }

    /** @return array<int, string> */
    private function emptyHeadingMap(): array
    {
        return [];
    }

    /** @return list<int> */
    private function emptyIntegers(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    private function emptyBlocks(): array
    {
        return [];
    }

    /** @return list<array{heading_path: list<string>, title: string|null, content: string, page_start: int|null, page_end: int|null, source_blocks: list<array<string, mixed>>}> */
    private function emptyFragments(): array
    {
        return [];
    }
}
