<?php

namespace App\Modules\Academic\Application;

use App\Modules\Configuration\Application\AcademicSourceDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;

final class CurricularSourceBlocks
{
    public function __construct(private readonly AcademicSourceDocument $documents) {}

    /** @return list<array{id: string, source_id: string, source_name: string, locator: string, text: string}> */
    public function forSource(AcademicSource $source): array
    {
        $blocks = [];
        $page = null;
        $heading = '';
        foreach (($source->documento['content'] ?? []) as $index => $node) {
            if (! is_array($node)) {
                continue;
            }
            $page = $node['attrs']['sourcePage'] ?? $page;
            $text = $this->text($node);
            if (($node['type'] ?? '') === 'heading') {
                $heading = $text;
            }
            $locator = $page !== null ? 'Página '.$page.', bloque '.($index + 1) : 'Bloque '.($index + 1);
            if (($node['type'] ?? '') === 'table') {
                $rows = $node['content'] ?? [];
                $header = is_array($rows[0] ?? null) ? $this->row($rows[0]) : '';
                $carry = [];
                foreach ($rows as $rowIndex => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $parts = [];
                    $occupied = [];
                    foreach ($carry as $column => $span) {
                        $parts[$column] = 'Columna '.($column + 1).' (celda combinada de fila anterior): '.$span['text'];
                        for ($position = $column; $position < $column + $span['width']; $position++) {
                            $occupied[$position] = true;
                        }
                        if ($span['remaining'] <= 1) {
                            unset($carry[$column]);
                        } else {
                            $carry[$column]['remaining']--;
                        }
                    }
                    $column = 0;
                    foreach ($row['content'] ?? [] as $cell) {
                        if (! is_array($cell)) {
                            continue;
                        }
                        while (isset($occupied[$column])) {
                            $column++;
                        }
                        $height = max(1, (int) ($cell['attrs']['rowspan'] ?? 1));
                        $width = max(1, (int) ($cell['attrs']['colspan'] ?? 1));
                        $cellText = $this->text($cell);
                        $parts[$column] = 'Columna '.($column + 1).($height > 1 || $width > 1 ? " (abarca {$height} filas y {$width} columnas)" : '').': '.$cellText;
                        if ($height > 1) {
                            $carry[$column] = ['remaining' => $height - 1, 'width' => $width, 'text' => $cellText];
                        }
                        $column += $width;
                    }
                    ksort($parts);
                    $rowText = implode("\n", $parts);
                    if ($rowText !== '') {
                        $blocks[] = $this->block($source, $index.'.'.$rowIndex, $locator.', fila '.($rowIndex + 1), $heading."\nCabecera de tabla: ".$header."\nFila ".($rowIndex + 1).': '.$rowText);
                    }
                }
            } elseif ($text !== '') {
                if (str_starts_with($text, 'Hoja «')) {
                    if (preg_match('/^Hoja «(.+?)» — /u', $text, $sheet)) {
                        preg_match_all('/(?:— |\n)([A-Z]{1,3}[1-9][0-9]*): /u', $text, $cells);
                        $references = $cells[1];
                        $locator = $sheet[1].($references !== [] ? '!'.$references[0].':'.$references[count($references) - 1] : ': rangos combinados');
                    }
                }
                $blocks[] = $this->block($source, (string) $index, $locator, $text);
            }
        }

        return $blocks;
    }

    /** @param array<string, mixed> $node */
    private function text(array $node): string
    {
        return trim($this->documents->text(['content' => [$node]]));
    }

    /** @param array<string, mixed> $row */
    private function row(array $row): string
    {
        $cells = [];
        foreach ($row['content'] ?? [] as $index => $cell) {
            if (! is_array($cell)) {
                continue;
            }
            $span = (int) ($cell['attrs']['rowspan'] ?? 1);
            $colspan = (int) ($cell['attrs']['colspan'] ?? 1);
            $cells[] = 'Columna '.($index + 1).($span > 1 || $colspan > 1 ? " (abarca {$span} filas y {$colspan} columnas)" : '').': '.$this->text($cell);
        }

        return implode("\n", $cells);
    }

    /** @return array{id: string, source_id: string, source_name: string, locator: string, text: string} */
    private function block(AcademicSource $source, string $suffix, string $locator, string $text): array
    {
        return ['id' => $source->id.':'.$suffix, 'source_id' => $source->id, 'source_name' => $source->nombre, 'locator' => $locator, 'text' => $text];
    }
}
