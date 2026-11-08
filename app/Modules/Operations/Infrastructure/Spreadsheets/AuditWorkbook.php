<?php

namespace App\Modules\Operations\Infrastructure\Spreadsheets;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Excel de auditoría: «Eventos» (uno por fila, por fecha) y «Resumen» (acciones por carrera). */
final class AuditWorkbook
{
    private const HEADERS = ['Fecha y hora', 'Usuario', 'Rol', 'Carrera', 'Acción', 'Sobre qué', 'Detalle', 'Resultado'];

    private const WIDTHS = [19, 30, 16, 24, 38, 48, 60, 11];

    /**
     * @param  list<array{occurred_at: DateTimeInterface, actor: string, role: string, career: string, action: string, target: string, details: string, result: string}>  $rows
     */
    public function build(array $rows, ?string $period): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('Auditoría'.($period !== null ? " {$period}" : ''))->setCreator('Sílabos UEB');

        $events = $book->getActiveSheet()->setTitle('Eventos');
        $events->fromArray(self::HEADERS, null, 'A1');
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $events->setCellValue("A{$line}", Date::PHPToExcel($row['occurred_at']));
            $events->fromArray([$row['actor'], $row['role'], $row['career'], $row['action'], $row['target'], $row['details'], $row['result']], null, "B{$line}");
        }
        $last = max(2, count($rows) + 1);
        $events->getStyle("A2:A{$last}")->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
        $events->getStyle("F2:G{$last}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $this->table($events, count(self::HEADERS), $last, self::WIDTHS);

        $summary = $book->createSheet()->setTitle('Resumen');
        $careers = array_values(array_unique(array_map(fn (array $row): string => $row['career'] !== '' ? $row['career'] : 'Sin carrera', $rows)));
        sort($careers);
        $counts = [];
        foreach ($rows as $row) {
            $career = $row['career'] !== '' ? $row['career'] : 'Sin carrera';
            $counts[$row['action']][$career] = ($counts[$row['action']][$career] ?? 0) + 1;
        }
        ksort($counts);
        $summary->fromArray(['Acción', ...$careers, 'Total'], null, 'A1');
        $line = 2;
        foreach ($counts as $action => $byCareer) {
            $values = array_map(fn (string $career): int => $byCareer[$career] ?? 0, $careers);
            $summary->fromArray([$action, ...$values, array_sum($values)], null, "A{$line}", true);
            $line++;
        }
        $this->table($summary, count($careers) + 2, max(2, $line - 1), [38, ...array_fill(0, count($careers) + 1, 16)]);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @param  list<int>  $widths */
    private function table(Worksheet $sheet, int $columns, int $lastRow, array $widths): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columns);
        $header = $sheet->getStyle("A1:{$lastColumn}1");
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A5F');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
    }
}
