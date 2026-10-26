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
    public function for(string $subjectName, string $subjectCode, array $blocks, array $subjects = [], ?string $field = null, ?string $career = null): array
    {
        $sections = $this->sections($subjectName, $subjectCode, $blocks, $subjects, $career);

        return array_values(array_map(fn (array $section): string => 'Documento: '.$section['document'].($section['page'] !== null ? ' · Página: '.$section['page'] : '')."\n".$section['text'], $this->select($sections, 0, $field, self::LIMIT, true)));
    }

    /**
     * @param  list<string|array{text: string, document: string, page: int|null, source?: int}>  $blocks
     * @param  list<array{name: string, code: string}>  $subjects
     * @param  list<array{key: string, label: string, columns: list<string>, section?: array{key: string, label: string}, format?: string, template?: array<string, mixed>, components?: array<string, mixed>}>  $fields
     * @return list<string>
     */
    public function context(string $subjectName, string $subjectCode, array $blocks, array $subjects, array $fields, ?string $career = null, ?string $focus = null, int $limit = 32000): array
    {
        $sections = $this->sections($subjectName, $subjectCode, $blocks, $subjects, $career);
        $directBudget = min(self::LIMIT, (int) floor($limit * 0.75));
        $direct = $this->select($sections, 0, $focus, $directBudget);
        if ($direct === []) {
            return [];
        }
        $search = $focus ?? implode(' ', array_map(fn (array $field): string => $field['label'].' '.implode(' ', $field['columns']).' '.($field['section']['label'] ?? ''), $fields));
        $shared = $this->select($sections, null, $search, min(8000, $limit - $directBudget));
        $fragments = [];
        foreach ([['subject', $direct], ['career_context', $shared]] as [$scope, $selected]) {
            foreach ($selected as $index => $section) {
                $fragments[] = json_encode([
                    'id' => 'fragment-'.substr(hash('sha256', $section['source'].'|'.$section['document'].'|'.$index), 0, 20),
                    'scope' => $scope, 'document' => $section['document'], 'page' => $section['page'],
                    'heading' => $section['heading'], 'text' => $section['text'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        }

        return $fragments;
    }

    /**
     * @param  list<string|array{text: string, document: string, page: int|null, source?: int}>  $blocks
     * @param  list<array{name: string, code: string}>  $subjects
     * @return list<array{text: string, owner: int|null, document: string, source: int|string, page: int|null, heading: string, allowed: bool}>
     */
    private function sections(string $subjectName, string $subjectCode, array $blocks, array $subjects, ?string $career): array
    {
        $subjects = array_values(array_filter($subjects, fn (array $subject): bool => $subject['name'] !== $subjectName));
        array_unshift($subjects, ['name' => $subjectName, 'code' => $subjectCode]);
        $sections = [];
        $owner = null;
        $document = null;
        $allowed = true;
        $heading = '';
        foreach ($blocks as $block) {
            $text = is_string($block) ? $block : $block['text'];
            $source = is_string($block) ? 'Documento' : $block['document'];
            $sourceId = is_string($block) ? $source : ($block['source'] ?? $source);
            $page = is_string($block) ? null : $block['page'];
            if ($sourceId !== $document) {
                $owner = null;
                $document = $sourceId;
                $allowed = true;
                $heading = '';
            }
            $piece = '';
            $flush = function () use (&$sections, &$piece, &$owner, $source, $sourceId, &$page, &$heading, &$allowed): void {
                if (trim($piece) !== '') {
                    $sections[] = ['text' => trim($piece), 'owner' => $owner, 'document' => $source, 'source' => $sourceId, 'page' => $page, 'heading' => $heading, 'allowed' => $allowed];
                }
                $piece = '';
            };
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $title = preg_match('/^#{1,6}\s/u', $line) === 1;
                $careerBoundary = preg_match('/^(?:Carrera del documento: |#{1,6}\s*.*?\bcarrera\s+de\s+)(.+)$/iu', $line, $careerMatch) === 1;
                $matches = [];
                foreach ($subjects as $key => $subject) {
                    if ($this->matches($line, $subject['name'], $subject['code'])) {
                        $matches[] = $key;
                    }
                }
                $subjectBoundary = $matches !== [] || $this->subjectHeading($line);
                if ($careerBoundary || $subjectBoundary || $title) {
                    $flush();
                    if ($title) {
                        $heading = trim(ltrim($line, '# '));
                    }
                    if ($careerBoundary) {
                        $allowed = $career === null || $this->normalize($careerMatch[1]) === $this->normalize($career);
                        $owner = null;
                    } elseif ($subjectBoundary) {
                        $owner = trim($line) === 'Asignatura:' ? null : ($allowed && count($matches) === 1 && preg_match('/\.{3,}/u', $line) !== 1 ? $matches[0] : -1);
                    } elseif (str_contains($this->normalize($heading), 'carrera') || str_contains($this->normalize($heading), 'perfil de egreso')) {
                        $owner = null;
                    }
                }
                if (preg_match('/^Contexto:.*\bPágina (\d+)\s*$/u', $line, $pageMatch) === 1) {
                    $page = (int) $pageMatch[1];
                }
                $piece .= $line."\n";
            }
            $flush();
        }

        return $sections;
    }

    /**
     * @param  list<array{text: string, owner: int|null, document: string, source: int|string, page: int|null, heading: string, allowed: bool}>  $sections
     * @return array<int, array{text: string, owner: int|null, document: string, source: int|string, page: int|null, heading: string, allowed: bool}>
     */
    private function select(array $sections, ?int $owner, ?string $field, int $limit, bool $adjacent = false): array
    {
        $candidates = [];
        $terms = $field === null ? [] : $this->terms($field);
        foreach ($sections as $index => $section) {
            if ($section['owner'] !== $owner || ! $section['allowed']) {
                continue;
            }
            $score = 10;
            $normalized = $this->normalize($section['heading'].' '.$section['text']);
            foreach ($terms as $term) {
                $stem = rtrim($term, 'es');
                if (str_contains($normalized, $term) || (strlen($stem) >= 4 && str_contains($normalized, $stem))) {
                    $score += 20;
                }
            }
            if (preg_match('/\bindice\b|\btabla de contenidos\b/u', $normalized) === 1 || preg_match('/\.{3,}/u', $section['text']) === 1) {
                continue;
            }
            if ($owner === null && $score === 10) {
                continue;
            }
            $candidates[$index] = max($candidates[$index] ?? PHP_INT_MIN, $score);
            if ($field !== null && $score > 10) {
                foreach ([$index - 1, $index + 1] as $neighbor) {
                    if (isset($sections[$neighbor]) && $sections[$neighbor]['owner'] === $owner && $sections[$neighbor]['allowed'] && $sections[$neighbor]['source'] === $section['source']) {
                        $candidates[$neighbor] = max($candidates[$neighbor] ?? 0, $score - 1);
                    }
                }
            }
            if ($adjacent && isset($sections[$index - 1]) && $sections[$index - 1]['owner'] === null && $sections[$index - 1]['allowed'] && $sections[$index - 1]['source'] === $section['source']) {
                $candidates[$index - 1] = max($candidates[$index - 1] ?? 0, 5);
            }
        }
        arsort($candidates);
        $picked = [];
        $total = 0;
        foreach ($candidates as $index => $score) {
            if ($total >= $limit || count($picked) >= 48) {
                break;
            }
            $section = $sections[$index];
            $section['text'] = mb_substr($section['text'], 0, $limit - $total);
            $picked[$index] = $section;
            $total += mb_strlen($section['text']);
        }
        if ($field === null) {
            ksort($picked);
        }

        return $picked;
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
