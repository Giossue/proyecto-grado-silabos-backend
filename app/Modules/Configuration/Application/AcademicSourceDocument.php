<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use Illuminate\Validation\ValidationException;

final class AcademicSourceDocument
{
    /** @var list<string> */
    private const BLOCK_NODES = [
        'paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem',
        'horizontalRule', 'table', 'tableRow', 'tableCell', 'tableHeader', 'sourceImage',
    ];

    /** @var list<string> */
    private const INLINE_NODES = ['text', 'hardBreak'];

    /** @var list<string> */
    private const MARKS = ['bold', 'italic', 'strike', 'code', 'link'];

    /** @return array{schemaVersion: int, type: string, content: list<array<string, mixed>>} */
    public function normalize(mixed $document): array
    {
        if (! is_array($document) || array_is_list($document) || ($document['type'] ?? null) !== 'doc') {
            throw ValidationException::withMessages(['document' => 'El documento estructurado no es válido.']);
        }
        $content = $document['content'] ?? null;
        if (! is_array($content) || ! array_is_list($content)) {
            throw ValidationException::withMessages(['document.content' => 'El contenido del documento debe ser una lista.']);
        }

        $nodes = 0;
        $characters = 0;
        $normalized = [];
        foreach ($content as $node) {
            $normalizedNode = $this->normalizeNode($node, 1, $nodes, $characters);
            if ($normalizedNode !== null) {
                $normalized[] = $normalizedNode;
            }
        }
        if ($nodes > 20000 || $characters > 1000000) {
            throw ValidationException::withMessages(['document' => 'El documento supera el tamaño permitido.']);
        }

        return ['schemaVersion' => 1, 'type' => 'doc', 'content' => $normalized];
    }

    /** @return array{schemaVersion: int, type: string, content: list<array<string, mixed>>} */
    public function fromLegacyText(?string $text): array
    {
        $content = [];
        foreach (preg_split('/\n{2,}/u', trim(str_replace(["\r\n", "\r"], "\n", (string) $text))) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+)$/us', $block, $match) === 1) {
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => strlen($match[1])],
                    'content' => [['type' => 'text', 'text' => trim($match[2])]],
                ];
            } else {
                $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $block]]];
            }
        }

        return ['schemaVersion' => 1, 'type' => 'doc', 'content' => $content];
    }

    /** @param array<string, mixed> $document */
    public function text(array $document): string
    {
        $blocks = [];
        foreach ($document['content'] ?? [] as $node) {
            if (is_array($node)) {
                $value = trim($this->nodeText($node));
                if ($value !== '') {
                    $blocks[] = $value;
                }
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function withAssetUrls(AcademicSource $source, array $document): array
    {
        $document['content'] = array_map(
            fn (mixed $node): mixed => is_array($node) ? $this->withNodeAssetUrl($source, $node) : $node,
            is_array($document['content'] ?? null) ? $document['content'] : [],
        );

        return $document;
    }

    /** @return array<string, mixed>|null */
    private function normalizeNode(mixed $node, int $depth, int &$nodes, int &$characters): ?array
    {
        if (! is_array($node) || array_is_list($node) || $depth > 20) {
            throw ValidationException::withMessages(['document' => 'El documento contiene un bloque inválido.']);
        }
        $type = $node['type'] ?? null;
        if (! is_string($type) || ! in_array($type, [...self::BLOCK_NODES, ...self::INLINE_NODES], true)) {
            throw ValidationException::withMessages(['document' => 'El documento contiene un tipo de bloque no permitido.']);
        }
        $nodes++;
        $result = ['type' => $type];
        if ($type === 'text') {
            if (! array_key_exists('text', $node)) {
                throw ValidationException::withMessages(['document' => 'El documento contiene texto inválido.']);
            }
            $text = $node['text'];
            // Laravel converts empty request strings to null before validation.
            if ($text === '' || $text === null) {
                return null;
            }
            if (! is_string($text)) {
                throw ValidationException::withMessages(['document' => 'El documento contiene texto inválido.']);
            }
            $characters += mb_strlen($text);
            $result['text'] = $text;
            $marks = [];
            foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
                if (! is_array($mark) || ! is_string($mark['type'] ?? null) || ! in_array($mark['type'], self::MARKS, true)) {
                    continue;
                }
                $clean = ['type' => $mark['type']];
                if ($mark['type'] === 'link') {
                    $href = is_array($mark['attrs'] ?? null) ? ($mark['attrs']['href'] ?? null) : null;
                    if (is_string($href) && preg_match('~^(https?://|mailto:)~i', $href) === 1) {
                        $clean['attrs'] = ['href' => mb_substr($href, 0, 2000)];
                    } else {
                        continue;
                    }
                }
                $marks[] = $clean;
            }
            if ($marks !== []) {
                $result['marks'] = $marks;
            }

            return $result;
        }

        $attrs = $this->normalizeAttributes($type, is_array($node['attrs'] ?? null) ? $node['attrs'] : []);
        if ($attrs !== []) {
            $result['attrs'] = $attrs;
        }
        $children = $node['content'] ?? [];
        if (is_array($children) && array_is_list($children)) {
            $normalizedChildren = [];
            foreach ($children as $child) {
                $normalizedChild = $this->normalizeNode($child, $depth + 1, $nodes, $characters);
                if ($normalizedChild !== null) {
                    $normalizedChildren[] = $normalizedChild;
                }
            }
            if ($normalizedChildren !== []) {
                $result['content'] = $normalizedChildren;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function normalizeAttributes(string $type, array $attrs): array
    {
        $clean = [];
        if ($type === 'heading') {
            $clean['level'] = min(6, max(1, (int) ($attrs['level'] ?? 2)));
        }
        if ($type === 'orderedList') {
            $clean['start'] = max(1, (int) ($attrs['start'] ?? 1));
        }
        if (in_array($type, ['tableCell', 'tableHeader'], true)) {
            $clean['colspan'] = min(30, max(1, (int) ($attrs['colspan'] ?? 1)));
            $clean['rowspan'] = min(100, max(1, (int) ($attrs['rowspan'] ?? 1)));
            $clean['colwidth'] = null;
        }
        if ($type === 'sourceImage') {
            $objectId = $attrs['objectId'] ?? null;
            if (! is_string($objectId) || preg_match('/^[0-9a-f-]{36}$/i', $objectId) !== 1) {
                throw ValidationException::withMessages(['document' => 'El documento referencia una imagen inválida.']);
            }
            $clean = [
                'objectId' => $objectId,
                'alt' => mb_substr(is_string($attrs['alt'] ?? null) ? $attrs['alt'] : '', 0, 500),
                'title' => mb_substr(is_string($attrs['title'] ?? null) ? $attrs['title'] : '', 0, 500),
            ];
        }
        foreach (['sourcePage', 'sourceOrder'] as $key) {
            if (isset($attrs[$key]) && is_numeric($attrs[$key])) {
                $clean[$key] = max(0, (int) $attrs[$key]);
            }
        }

        return $clean;
    }

    /** @param array<string, mixed> $node */
    private function nodeText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if (($node['type'] ?? null) === 'hardBreak') {
            return "\n";
        }
        if (($node['type'] ?? null) === 'sourceImage') {
            return trim((string) (($node['attrs']['alt'] ?? null) ?: ($node['attrs']['title'] ?? '')));
        }
        $separator = in_array($node['type'] ?? null, ['tableRow', 'table'], true) ? "\n" : ' ';
        $values = [];
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $values[] = $this->nodeText($child);
            }
        }

        return implode($separator, array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withNodeAssetUrl(AcademicSource $source, array $node): array
    {
        if (($node['type'] ?? null) === 'sourceImage' && is_array($node['attrs'] ?? null)) {
            $objectId = $node['attrs']['objectId'] ?? null;
            if (is_string($objectId)) {
                $node['attrs']['src'] = route('api.v1.coordination.sources.assets.show', [
                    'source' => $source->id,
                    'asset' => $objectId,
                ]);
            }
        }
        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(
                fn (mixed $child): mixed => is_array($child) ? $this->withNodeAssetUrl($source, $child) : $child,
                $node['content'],
            );
        }

        return $node;
    }
}
