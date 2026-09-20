<?php

namespace App\Modules\Configuration\Domain\Data;

final readonly class AcademicSourceOcrPage
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public int $index,
        public string $markdown,
        public array $metadata,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function images(): array
    {
        $images = $this->metadata['images'] ?? [];

        if (! is_array($images) || ! array_is_list($images)) {
            return [];
        }

        return array_values(array_filter(
            $images,
            static fn (mixed $image): bool => is_array($image),
        ));
    }

    public function markdownWithTables(): string
    {
        $markdown = $this->markdown;
        $tables = $this->metadata['tables'] ?? [];

        if (! is_array($tables) || ! array_is_list($tables)) {
            return $markdown;
        }

        foreach ($tables as $table) {
            if (! is_array($table)) {
                continue;
            }

            $id = $table['id'] ?? null;
            $content = $table['content'] ?? null;
            if (! is_string($id) || trim($id) === '' || ! is_string($content) || trim($content) === '') {
                continue;
            }

            $pattern = '~\\[[^\\]]*\\]\\(\\s*(?:\\./)?'.preg_quote($id, '~').'(?:(?:\\s+)[^)]*)?\\s*\\)~';
            $replaced = preg_replace_callback(
                $pattern,
                static fn (): string => trim($content),
                $markdown,
                -1,
                $count,
            );
            if (is_string($replaced) && $count > 0) {
                $markdown = $replaced;

                continue;
            }

            // Conserva la tabla aunque Mistral no haya emitido su marcador en el
            // Markdown principal.
            $markdown = rtrim($markdown)."\n\n".trim($content);
        }

        return $markdown;
    }

    /**
     * @param  null|callable(string, array<string, mixed>): ?string  $imageUrlResolver
     */
    public function renderMarkdown(?callable $imageUrlResolver = null): string
    {
        $markdown = $this->markdownWithTables();

        foreach ($this->images() as $image) {
            $id = $image['id'] ?? null;
            if (! is_string($id) || trim($id) === '') {
                continue;
            }

            $url = $imageUrlResolver !== null ? $imageUrlResolver($id, $image) : null;
            $replacement = is_string($url) && trim($url) !== ''
                ? '!['.$id.']('.$url.')'
                : '*Imagen no disponible: '.$id.'*';
            $pattern = '~!\\[[^\\]]*\\]\\(\\s*(?:\\./)?'.preg_quote($id, '~').'(?:(?:\\s+)[^)]*)?\\s*\\)~';
            $replaced = preg_replace_callback(
                $pattern,
                static fn (): string => $replacement,
                $markdown,
                -1,
                $count,
            );
            if (is_string($replaced) && $count > 0) {
                $markdown = $replaced;
            }
        }

        return $markdown;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'markdown' => $this->markdown,
            ...self::withoutInlineImageData($this->metadata),
        ];
    }

    private static function withoutInlineImageData(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (in_array((string) $key, ['image_base64', 'image_data'], true)) {
                    continue;
                }
                $clean[$key] = self::withoutInlineImageData($item);
            }

            return $clean;
        }

        return $value;
    }
}
