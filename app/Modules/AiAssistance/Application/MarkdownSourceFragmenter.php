<?php

namespace App\Modules\AiAssistance\Application;

class MarkdownSourceFragmenter
{
    /**
     * @return list<array{heading_path: list<string>, title: string|null, content: string}>
     */
    public function fragment(?string $markdown, int $maxCharacters = 4000): array
    {
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", (string) $markdown));
        if ($markdown === '') {
            return [];
        }

        $headings = [];
        $title = null;
        $body = [];
        $sections = [];

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', trim($line), $matches) === 1) {
                $this->appendSection($sections, $headings, $title, $body, $maxCharacters);
                $body = [];
                $level = strlen($matches[1]);
                $heading = mb_substr(trim($matches[2]), 0, 180);
                $headings = array_slice($headings, 0, $level - 1);
                $headings[$level - 1] = $heading;
                $title = $heading;

                continue;
            }
            $body[] = $line;
        }
        $this->appendSection($sections, $headings, $title, $body, $maxCharacters);

        return $sections;
    }

    /**
     * @param  list<array{heading_path: list<string>, title: string|null, content: string}>  $sections
     * @param  array<int, string>  $headings
     * @param  list<string>  $body
     */
    private function appendSection(array &$sections, array $headings, ?string $title, array $body, int $maxCharacters): void
    {
        $path = array_values($headings);
        foreach ($this->chunks(trim(implode("\n", $body)), $maxCharacters) as $chunk) {
            $sections[] = ['heading_path' => $path, 'title' => $title, 'content' => $chunk];
        }
    }

    /** @return list<string> */
    private function chunks(string $content, int $maxCharacters): array
    {
        if ($content === '') {
            return [];
        }
        $maxCharacters = max(200, $maxCharacters);
        $paragraphs = preg_split('/\n{2,}/u', $content) ?: [$content];
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            while (mb_strlen($paragraph) > $maxCharacters) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = trim(mb_substr($paragraph, 0, $maxCharacters));
                $paragraph = trim(mb_substr($paragraph, $maxCharacters));
            }
            $candidate = $current === '' ? $paragraph : "{$current}\n\n{$paragraph}";
            if (mb_strlen($candidate) > $maxCharacters) {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
