<?php

namespace App\Modules\Identity\Application\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetTemplate
{
    /**
     * @param  list<string>  $headers
     * @param  array<int, array<int, string>>  $selects  posición de columna (desde 0) => opciones permitidas
     * @param  list<string>  $notes  indicaciones a la derecha, que la importación ignora
     */
    public function download(string $filename, array $headers, array $selects, array $notes): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $selects, $notes): void {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('Datos');
            $lists = $book->createSheet()->setTitle('Listas');

            foreach ($headers as $index => $header) {
                $sheet->setCellValue([$index + 1, 1], $header);
                $sheet->getColumnDimensionByColumn($index + 1)->setWidth(max(18, mb_strlen($header) + 4));
            }
            $sheet->getStyle([1, 1, count($headers), 1])->getFont()->setBold(true);
            $sheet->getStyle([1, 1, count($headers), 1])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
            $sheet->freezePane('A2');

            foreach ($selects as $position => $options) {
                $this->addSelect($sheet, $lists, $position, $options);
            }

            $noteColumn = count($headers) + 2;
            $last = Coordinate::stringFromColumnIndex($noteColumn);
            $lastRow = max(10, count($notes) * 2 + 2);
            $sheet->setCellValue([$noteColumn, 1], 'Indicaciones');
            $sheet->getStyle([$noteColumn, 1])->getFont()->setBold(true);
            $sheet->mergeCells("{$last}2:{$last}{$lastRow}");
            $sheet->setCellValue("{$last}2", implode("\n\n", $notes));
            $sheet->getColumnDimensionByColumn($noteColumn)->setWidth(70);
            $sheet->getStyle("{$last}2:{$last}{$lastRow}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle("{$last}2:{$last}{$lastRow}")->getFont()->getColor()->setRGB('64748B');
            $sheet->getStyle("{$last}1:{$last}{$lastRow}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);

            $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param array<int, string> $options */
    private function addSelect(Worksheet $sheet, Worksheet $lists, int $position, array $options): void
    {
        if ($options === []) {
            return;
        }
        $listColumn = Coordinate::stringFromColumnIndex($position + 1);
        foreach ($options as $row => $option) {
            $lists->setCellValue([$position + 1, $row + 1], $option);
        }
        $range = "Listas!\${$listColumn}\$1:\${$listColumn}\$".count($options);
        $column = Coordinate::stringFromColumnIndex($position + 1);
        for ($row = 2; $row <= SpreadsheetReader::MAX_ROWS + 1; $row++) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Valor no válido')
                ->setError('Elija una opción de la lista.')
                ->setFormula1($range);
        }
    }
}
