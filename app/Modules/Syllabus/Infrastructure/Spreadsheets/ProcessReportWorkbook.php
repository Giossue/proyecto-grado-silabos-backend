<?php

namespace App\Modules\Syllabus\Infrastructure\Spreadsheets;

use App\Modules\Syllabus\Application\Queries\ProcessReport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Excel del reporte de una convocatoria: Resumen, Por carrera, Envíos por día y Pendientes. */
final class ProcessReportWorkbook
{
    public const STATE_LABELS = [
        'sin_iniciar' => 'Sin iniciar', 'borrador' => 'Borrador', 'en_revision' => 'En revisión',
        'correccion_solicitada' => 'Devuelto', 'aprobado' => 'Aprobado',
    ];

    /** @param  array<string, mixed>  $report */
    public function build(array $report): Spreadsheet
    {
        /** @var array{period: string|null, starts_at: string, due_at: string} $process */
        $process = $report['process'];
        /** @var array{total: int, approved_percent: float|int, on_time_percent: float|int|null, review_days: float|null} $kpis */
        $kpis = $report['kpis'];
        /** @var list<array<string, mixed>> $careers */
        $careers = $report['careers'];
        /** @var list<array{day: string, first: int, resubmitted: int}> $daily */
        $daily = $report['daily'];
        /** @var list<array{teachers: string, career: string, subject: string, parallel: string, state: string, late: bool}> $pending */
        $pending = $report['pending'];

        $book = new Spreadsheet;
        $book->getProperties()->setTitle("Reporte {$process['period']}")->setCreator('Sílabos UEB');

        $summary = $book->getActiveSheet()->setTitle('Resumen');
        $summary->fromArray([
            ['Indicador', 'Valor'],
            ['Convocatoria', (string) $process['period']],
            ['Inicio de elaboración', $process['starts_at']],
            ['Fecha de entrega', $process['due_at']],
            ['Sílabos', $kpis['total']],
            ['Aprobados (%)', $kpis['approved_percent']],
            ['Enviados a tiempo (%)', $kpis['on_time_percent'] ?? 'Sin envíos'],
            ['Días promedio de revisión', $kpis['review_days'] ?? 'Sin aprobados'],
        ], null, 'A1', true);
        $this->table($summary, 2, 8, [30, 30]);

        $byCareer = $book->createSheet()->setTitle('Por carrera');
        $byCareer->fromArray(['Carrera', 'Total', ...array_values(self::STATE_LABELS), 'Atrasados', 'Devoluciones de Coordinación', 'Devoluciones de Biblioteca', 'Avance (%)'], null, 'A1');
        foreach ($careers as $index => $row) {
            /** @var array<string, int> $states */
            $states = $row['states'];
            /** @var array{coordinacion: int, biblioteca: int} $returns */
            $returns = $row['returns'];
            $byCareer->fromArray([
                $row['career'], $row['total'], ...array_map(fn (string $state): int => $states[$state], ProcessReport::STATES),
                $row['late'], $returns['coordinacion'], $returns['biblioteca'], $row['progress'],
            ], null, 'A'.($index + 2), true);
        }
        $this->table($byCareer, 12, max(2, count($careers) + 1), [30, 8, 11, 10, 12, 10, 10, 11, 16, 16, 11]);

        $days = $book->createSheet()->setTitle('Envíos por día');
        $days->fromArray(['Día', 'Primeros envíos', 'Reenvíos'], null, 'A1');
        foreach ($daily as $index => $row) {
            $days->fromArray([$row['day'], $row['first'], $row['resubmitted']], null, 'A'.($index + 2), true);
        }
        $this->table($days, 3, max(2, count($daily) + 1), [14, 16, 12]);

        $late = $book->createSheet()->setTitle('Pendientes');
        $late->fromArray(['Docente', 'Carrera', 'Asignatura', 'Paralelo', 'Estado', 'Atrasado'], null, 'A1');
        foreach ($pending as $index => $row) {
            $late->fromArray([$row['teachers'], $row['career'], $row['subject'], $row['parallel'], self::STATE_LABELS[$row['state']] ?? $row['state'], $row['late'] ? 'Sí' : 'No'], null, 'A'.($index + 2), true);
        }
        $this->table($late, 6, max(2, count($pending) + 1), [32, 26, 36, 10, 14, 10]);

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
