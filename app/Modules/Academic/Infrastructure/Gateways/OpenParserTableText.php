<?php

namespace App\Modules\Academic\Infrastructure\Gateways;

use Illuminate\Support\Str;

final class OpenParserTableText
{
    /** @var array<int, string> */
    private array $headers = [];

    private ?int $subjectColumn = null;

    private string $subject = '';

    private int $columns = 0;

    /** @param array<string, mixed> $element */
    public function render(array $element, string $context, string $career = ''): string
    {
        $columns = min(100, max(0, (int) ($element['column_count'] ?? 0)));
        if ($columns !== $this->columns) {
            $this->headers = [];
            $this->subjectColumn = null;
            $this->subject = '';
            $this->columns = $columns;
        }
        $rows = $this->rows($element);
        $headerRow = null;
        foreach ($rows[0] ?? [] as $column => $text) {
            $name = Str::lower(Str::ascii($text));
            if (preg_match('/^(?:nombre de la )?(?:asignatura|materia)(?: a impartir)?$/u', $name) === 1) {
                $this->headers = $rows[0];
                $this->subjectColumn = $column;
                $this->subject = '';
                $headerRow = 0;
                break;
            }
        }
        $lines = [];
        foreach ($rows as $index => $row) {
            if ($index === $headerRow) {
                continue;
            }
            if ($this->subjectColumn === null) {
                $lines[] = implode(' | ', $row);

                continue;
            }
            $name = $row[$this->subjectColumn] ?? '';
            if ($name !== '') {
                $this->subject = $name;
            }
            if ($career !== '') {
                $lines[] = 'Carrera del documento: '.$career;
            }
            $lines[] = 'Asignatura: '.$this->subject;
            if ($context !== '') {
                $lines[] = 'Contexto: '.$context;
            }
            foreach ($row as $column => $text) {
                if ($column !== $this->subjectColumn && $text !== '') {
                    $label = $this->headers[$column] ?? '';
                    $lines[] = ($label !== '' ? $label.': ' : '').$text;
                }
            }
            $lines[] = '';
        }
        if ($this->subjectColumn !== null) {
            $lines[] = 'Asignatura:';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<int, array<int, string>>
     */
    private function rows(array $element): array
    {
        $rows = [];
        foreach (is_array($element['cells'] ?? null) ? $element['cells'] : [] as $cell) {
            if (! is_array($cell) || ! is_string($cell['text'] ?? null)) {
                continue;
            }
            $row = max(0, (int) ($cell['row_index'] ?? 0));
            $column = max(0, (int) ($cell['column_index'] ?? 0));
            $lastRow = min(5000, $row + max(1, (int) ($cell['row_span'] ?? 1)));
            $lastColumn = min(100, $column + max(1, (int) ($cell['column_span'] ?? 1)));
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(str_replace(['\\n', "\r", "\n"], ' ', $cell['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            for ($r = $row; $r < $lastRow; $r++) {
                for ($c = $column; $c < $lastColumn; $c++) {
                    $rows[$r][$c] = $text;
                }
            }
        }
        ksort($rows);
        foreach ($rows as &$row) {
            ksort($row);
        }

        return $rows;
    }
}
