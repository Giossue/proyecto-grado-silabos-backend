<?php

namespace App\Modules\Configuration\Domain;

final class UnitsTable
{
    private const MAX_WEEKS = 16;

    private const HOURS = ['acd_h', 'ape_h', 'aa_h'];

    private const FILL = 'C3D4EA';

    private const BAND = 'E1E9F4';

    private const HEADER_TEXT = '365F91';

    /**
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    public function table(array $unit, int $number, int &$week): array
    {
        $cell = fn (string $text, int $cols = 1, int $rows = 1, bool $head = false, ?string $align = null, bool $bold = false, bool $band = false): array => [
            'type' => $head ? 'tableHeader' : 'tableCell',
            'attrs' => array_filter([
                'colspan' => $cols,
                'rowspan' => $rows,
                'backgroundColor' => $head ? '#'.self::FILL : ($band ? '#'.self::BAND : null),
                'textAlign' => $align ?? ($head ? 'center' : null),
            ], fn (mixed $v): bool => $v !== null),
            'content' => [$text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text] + ($head
                ? ['marks' => [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => '#'.self::HEADER_TEXT]]]]
                : ($bold ? ['marks' => [['type' => 'bold']]] : []))]]],
        ];
        $row = fn (array $cells): array => ['type' => 'tableRow', 'content' => $cells];
        $text = fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $weeks = array_values(array_filter(is_array($unit['semanas'] ?? null) ? $unit['semanas'] : [], 'is_array'));

        $rows = [
            $row([$cell('Unidad No.', 1, 2, true), $cell((string) $number, 1, 2, true), $cell('Nombre de la unidad', 3, 1, true), $cell($text($unit['nombre'] ?? null), 4)]),
            $row([$cell('Resultados de aprendizaje', 3, 1, true), $cell($text($unit['resultado'] ?? null), 4)]),
            $row([$cell('Contenidos temáticos de la unidad', 1, 3, true), $cell('Semanas ('.self::MAX_WEEKS.')', 1, 3, true), $cell('Horas por semana — Organización del aprendizaje', 3, 1, true), $cell('ACTIVIDADES DE APRENDIZAJE', 3, 1, true), $cell('Evaluación de los aprendizajes', 1, 3, true)]),
            $row([$cell('DOCENCIA', 1, 1, true), $cell('ESTUDIANTE', 2, 1, true), $cell('Aprendizaje en contacto con el docente (ACD)', 1, 2, true), $cell('Aprendizaje práctico-experimental (APE)', 1, 2, true), $cell('Aprendizaje autónomo (AA)', 1, 2, true)]),
            $row([$cell('ACD', 1, 1, true), $cell('APE', 1, 1, true), $cell('AA', 1, 1, true)]),
        ];
        $totals = [0.0, 0.0, 0.0];
        foreach ($weeks as $item) {
            $week++;
            foreach (self::HOURS as $i => $key) {
                $totals[$i] += (float) str_replace(',', '.', $text($item[$key] ?? null));
            }
            $band = $week % 2 === 1;
            $rows[] = $row([
                $cell($text($item['contenidos'] ?? null), 1, 1, false, null, false, $band), $cell((string) $week, 1, 1, false, 'center', false, $band),
                $cell($text($item['acd_h'] ?? null), 1, 1, false, 'center', false, $band), $cell($text($item['ape_h'] ?? null), 1, 1, false, 'center', false, $band), $cell($text($item['aa_h'] ?? null), 1, 1, false, 'center', false, $band),
                $cell($text($item['acd'] ?? null), 1, 1, false, null, false, $band), $cell($text($item['ape'] ?? null), 1, 1, false, null, false, $band), $cell($text($item['aa'] ?? null), 1, 1, false, null, false, $band), $cell($text($item['evaluacion'] ?? null), 1, 1, false, null, false, $band),
            ]);
        }
        $fmt = fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        $rows[] = $row([$cell('Total, horas', 2, 1, true, 'right'), $cell($fmt($totals[0]), 1, 1, true), $cell($fmt($totals[1]), 1, 1, true), $cell($fmt($totals[2]), 1, 1, true), $cell('', 4, 1, true)]);

        return ['type' => 'table', 'content' => $rows];
    }
}
