<?php

namespace App\Modules\Academic\Application;

use Illuminate\Support\Str;

final class FichaEvidence
{
    private const LIMIT = 24000;

    /**
     * @param  list<string|array{text: string, document: string, page: int|null, source?: int}>  $blocks
     * @param  list<array{name: string, code: string}>  $subjects
     * @return list<string>
     */
    public function for(string $subjectName, string $subjectCode, array $blocks, array $subjects = [], ?string $field = null): array
    {
        $subjects = array_values(array_filter($subjects, fn (array $subject): bool => $subject['name'] !== $subjectName));
        array_unshift($subjects, ['name' => $subjectName, 'code' => $subjectCode]);
        $sections = [];
        $owner = null;
        $document = null;
        foreach ($blocks as $block) {
            $text = is_string($block) ? $block : $block['text'];
            $source = is_string($block) ? 'Documento' : $block['document'];
            $sourceId = is_string($block) ? $source : ($block['source'] ?? $source);
            $page = is_string($block) ? null : $block['page'];
            if ($sourceId !== $document) {
                $owner = null;
                $document = $sourceId;
            }
            $piece = '';
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $matches = [];
                foreach ($subjects as $key => $subject) {
                    if ($this->matches($line, $subject['name'], $subject['code'])) {
                        $matches[] = $key;
                    }
                }
                $boundary = $matches !== [] || $this->subjectHeading($line);
                if ($boundary) {
                    if (trim($piece) !== '') {
                        $sections[] = ['text' => trim($piece), 'owner' => $owner, 'document' => $source, 'source' => $sourceId, 'page' => $page];
                    }
                    $piece = '';
                    $owner = count($matches) === 1 && preg_match('/\.{3,}/u', $line) !== 1 ? $matches[0] : -1;
                }
                $piece .= $line."\n";
            }
            if (trim($piece) !== '') {
                $sections[] = ['text' => trim($piece), 'owner' => $owner, 'document' => $source, 'source' => $sourceId, 'page' => $page];
            }
        }
        $candidates = [];
        $terms = $field === null ? [] : $this->terms($field);
        foreach ($sections as $index => $section) {
            if ($section['owner'] !== 0) {
                continue;
            }
            $score = 10;
            $normalized = $this->normalize($section['text']);
            foreach ($terms as $term) {
                if (str_contains($normalized, $term)) {
                    $score += 20;
                }
            }
            if (preg_match('/\bindice\b|\btabla de contenidos\b/u', $normalized) === 1 || preg_match('/\.{3,}/u', $section['text']) === 1) {
                $score -= 30;
            }
            $candidates[$index] = max($candidates[$index] ?? PHP_INT_MIN, $score);
            if ($field !== null && $score > 10) {
                foreach ([$index - 1, $index + 1] as $neighbor) {
                    if (($sections[$neighbor]['owner'] ?? null) === 0 && $sections[$neighbor]['source'] === $section['source']) {
                        $candidates[$neighbor] = max($candidates[$neighbor] ?? 0, $score - 1);
                    }
                }
            }
            if (isset($sections[$index - 1]) && $sections[$index - 1]['owner'] === null && $sections[$index - 1]['source'] === $section['source']) {
                $candidates[$index - 1] = max($candidates[$index - 1] ?? 0, 5);
            }
        }
        arsort($candidates);
        $picked = [];
        $total = 0;
        foreach ($candidates as $index => $score) {
            if ($score < 0) {
                continue;
            }
            $section = $sections[$index];
            $text = $section['text'];
            $remaining = self::LIMIT - $total;
            if ($remaining < 200) {
                break;
            }
            if (mb_strlen($text) > $remaining) {
                $text = mb_substr($text, 0, $remaining);
            }
            $picked[$index] = 'Documento: '.$section['document'].($section['page'] !== null ? ' · Página: '.$section['page'] : '')."\n".$text;
            $total += mb_strlen($text);
        }
        if ($field === null) {
            ksort($picked);
        }

        return array_values($picked);
    }

    private function subjectHeading(string $line): bool
    {
        $text = Str::lower(Str::ascii(trim($line)));

        return preg_match('/^(?:[#*\s|\d.()-]*)(?:(?:nombre\s+(?:de\s+(?:la\s+)?)?)?(?:asignatura|materia)|codigo(?:\s+de\s+(?:la\s+)?asignatura)?)(?:\s*[:|]|\s*$)/u', $text) === 1;
    }

    private function matches(string $line, string $name, string $code): bool
    {
        $text = $this->normalize($line);
        $name = $this->normalize($name);
        $code = $this->normalize($code);
        if ($code !== '' && str_contains(' '.$text.' ', ' '.$code.' ')) {
            return true;
        }
        if ($name !== '' && str_contains(' '.$text.' ', ' '.$name.' ')) {
            return true;
        }
        $terms = $this->terms($name);

        return count($terms) >= 2 && mb_strlen($text) < 250
            && array_all($terms, fn (string $term): bool => str_contains(' '.$text.' ', ' '.$term.' '));
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        return array_values(array_filter(explode(' ', $this->normalize($text)), fn (string $word): bool => strlen($word) > 2 && ! in_array($word, ['del', 'las', 'los', 'una', 'uno', 'por', 'para', 'con', 'que', 'frente'], true)));
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))) ?? '');
    }
}
