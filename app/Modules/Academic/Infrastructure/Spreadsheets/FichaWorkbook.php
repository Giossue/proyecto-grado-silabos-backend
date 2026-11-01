<?php

namespace App\Modules\Academic\Infrastructure\Spreadsheets;

use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Domain\FichaWorkbookDefinition;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\FreeText;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

final class FichaWorkbook
{
    public const FORMAT = 'ficha_xlsx_v1';

    private const TABLE_ROWS = 40;

    private const MAX_CELLS = 250000;

    public function __construct(private readonly FichaFieldValues $values) {}

    public function create(FichaWorkbookDefinition $definition): Spreadsheet
    {
        $this->assertSize($definition);
        $book = new Spreadsheet;
        $guide = $book->getActiveSheet()->setTitle('Indicaciones');
        $guideRows = [
            ['Proyecto de carrera', $definition->careerName],
            ['1', 'Complete las celdas de contenido de cada hoja. Cada hoja corresponde a un campo de la plantilla.'],
            ['2', 'No cambie los nombres de las hojas, los encabezados ni los datos de las materias.'],
            ['3', 'Las celdas vacías conservan los valores actuales. No borran contenido de las fichas.'],
            ['4', 'Para listas, escriba un elemento por línea empezando con «- ». Para párrafos, use saltos de línea normales.'],
            ['5', 'Las tablas de listas admiten hasta 40 filas por materia. Las tablas fijas conservan sus filas y rótulos.'],
            ['6', 'Cargue el archivo en el sitio, revise la vista previa y confirme qué materias y campos aplicar.'],
            ['7', 'Si cambian los campos de la plantilla o las materias, descargue un Excel nuevo.'],
        ];
        foreach ($guideRows as $index => $cells) {
            foreach ($cells as $column => $content) {
                $this->text($guide, Coordinate::stringFromColumnIndex($column + 1).($index + 1), $content);
            }
        }
        $guide->getColumnDimension('A')->setWidth(25);
        $guide->getColumnDimension('B')->setWidth(95);
        $guide->getStyle('B1:B8')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        for ($row = 2; $row <= 8; $row++) {
            $guide->getRowDimension($row)->setRowHeight(45);
        }
        $guide->getStyle('A1:B1')->getFont()->setBold(true);
        $guide->getProtection()->setPassword(Str::random(24))->setSheet(true);
        $meta = $book->createSheet()->setTitle('_estructura');
        $meta->setCellValueExplicit('A1', 'UEB_FICHA_XLSX', DataType::TYPE_STRING);
        $this->text($meta, 'B1', self::FORMAT);
        $this->text($meta, 'A2', 'Carrera');
        $this->text($meta, 'B2', $definition->careerId);
        $this->text($meta, 'A3', 'Estructura');
        $this->text($meta, 'B3', $definition->fingerprint());
        $meta->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        foreach ($definition->fields as $field) {
            $sheet = $book->createSheet()->setTitle($field['sheet']);
            $headers = $this->headers($field['columns']);
            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $this->text($sheet, 'A1', $field['id']);
            $this->text($sheet, 'B1', $field['label']);
            $sheet->mergeCells('B1:'.$lastColumn.'1');
            $this->text($sheet, 'B2', $field['section']);
            $sheet->mergeCells('B2:'.$lastColumn.'2');
            foreach ($headers as $column => $header) {
                $this->text($sheet, Coordinate::stringFromColumnIndex($column + 1).'3', $header);
            }
            $row = 4;
            foreach ($definition->subjects as $subject) {
                $slots = $this->slots($field);
                for ($slot = 0; $slot < $slots; $slot++) {
                    $this->text($sheet, 'A'.$row, $subject['id']);
                    $this->text($sheet, 'B'.$row, $subject['code']);
                    $this->text($sheet, 'C'.$row, $subject['name']);
                    foreach ($field['columns'] as $column => $label) {
                        $fixed = $this->fixedCell($field, $slot, $column);
                        if ($fixed !== null) {
                            $this->text($sheet, Coordinate::stringFromColumnIndex($column + 4).$row, $fixed);
                        }
                    }
                    $sheet->getRowDimension($row)->setRowHeight($slots === 1 ? 95 : 35);
                    $row++;
                }
            }
            $range = 'D4:'.$lastColumn.($row - 1);
            $sheet->getStyle($range)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
            for ($index = 4; $index < $row; $index++) {
                foreach ($field['columns'] as $column => $label) {
                    if ($this->fixedCell($field, ($index - 4) % $this->slots($field), $column) !== null) {
                        $sheet->getStyle(Coordinate::stringFromColumnIndex($column + 4).$index)->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
                    }
                }
            }
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getStyle('B1:'.$lastColumn.($row - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle('B1:'.$lastColumn.'3')->getFont()->setBold(true);
            $sheet->getStyle('B3:'.$lastColumn.'3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF0');
            $sheet->getColumnDimension('A')->setVisible(false);
            $sheet->getColumnDimension('B')->setWidth(20);
            $sheet->getColumnDimension('C')->setWidth(38);
            for ($column = 4; $column <= count($headers); $column++) {
                $sheet->getColumnDimensionByColumn($column)->setWidth($field['columns'] === [] ? 85 : 42);
            }
            $sheet->getProtection()->setPassword(Str::random(24))->setSheet(true);
            $sheet->freezePane('D4');
        }
        $book->getSecurity()->setLockStructure(true)->setWorkbookPassword(Str::random(24));
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @return list<array<string, mixed>> */
    public function read(string $path, FichaWorkbookDefinition $definition): array
    {
        $this->assertSize($definition);
        $this->assertArchive($path);
        try {
            $reader = new XlsxReader;
            $reader->setReadDataOnly(false);
            $this->assertDimensions($reader->listWorksheetInfo($path), $definition);
            $book = $reader->load($path);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('No se pudo abrir el Excel. Use el archivo .xlsx descargado desde el sitio.');
        }
        try {
            foreach ($book->getAllSheets() as $sheet) {
                foreach ($sheet->getCellCollection()->getCoordinates() as $address) {
                    if ($sheet->getCell($address)->getDataType() === DataType::TYPE_FORMULA) {
                        $this->fail('No se admiten fórmulas. Escriba o pegue solo el contenido.', $sheet, $address);
                    }
                }
            }
            $expectedNames = ['Indicaciones', '_estructura', ...array_column($definition->fields, 'sheet')];
            if ($book->getSheetNames() !== $expectedNames) {
                $this->fail('Las hojas del Excel cambiaron. Descargue una plantilla nueva y conserve sus nombres y orden.');
            }
            $meta = $book->getSheetByName('_estructura');
            if ($meta === null || $this->cell($meta, 'A1') !== 'UEB_FICHA_XLSX'
                || $this->cell($meta, 'B1') !== self::FORMAT || $this->cell($meta, 'B2') !== $definition->careerId
                || $this->cell($meta, 'B3') !== $definition->fingerprint()) {
                $this->fail('El Excel no corresponde a la carrera o la estructura actual. Descargue una plantilla nueva.');
            }
            $values = [];
            foreach ($definition->fields as $field) {
                $sheet = $book->getSheetByName($field['sheet']);
                if ($sheet === null) {
                    $this->fail('Falta una hoja de la plantilla. Descargue el Excel nuevamente.');
                }
                $headers = $this->headers($field['columns']);
                if ($this->cell($sheet, 'A1') !== $field['id'] || $this->cell($sheet, 'B1') !== $field['label'] || $this->cell($sheet, 'B2') !== $field['section']) {
                    $this->fail('No cambie los títulos ni la identificación de los campos de la plantilla.');
                }
                foreach ($headers as $column => $header) {
                    $this->expect($sheet, Coordinate::stringFromColumnIndex($column + 1).'3', $header);
                }
                $row = 4;
                foreach ($definition->subjects as $subject) {
                    $slots = $this->slots($field);
                    $data = [];
                    $hasAnswers = false;
                    for ($slot = 0; $slot < $slots; $slot++) {
                        $this->expect($sheet, 'A'.$row, $subject['id']);
                        $this->expect($sheet, 'B'.$row, $subject['code']);
                        $this->expect($sheet, 'C'.$row, $subject['name']);
                        $cells = [];
                        for ($column = 4; $column <= count($headers); $column++) {
                            $address = Coordinate::stringFromColumnIndex($column).$row;
                            $content = trim($this->cell($sheet, $address));
                            if (mb_strlen($content) > ($field['columns'] === [] ? 12000 : 1500)) {
                                $this->fail('El contenido supera el límite permitido.', $sheet, $address);
                            }
                            $fixed = $this->fixedCell($field, $slot, $column - 4);
                            if ($fixed !== null) {
                                $this->expect($sheet, $address, $fixed);
                            } elseif ($content !== '') {
                                $hasAnswers = true;
                            }
                            $cells[] = $content;
                        }
                        if (($field['table'] ?? null) !== null && ! FieldResponse::listTable($field['table']) || array_any($cells, fn (string $cell): bool => $cell !== '')) {
                            $data[] = $cells;
                        }
                        $row++;
                    }
                    if ($data !== [] && $hasAnswers) {
                        $raw = $field['columns'] === [] ? $this->textValue($data[0][0], $sheet) : ['rows' => $data];
                        $value = $this->values->clean($raw, $field['columns']);
                        if ($value === null) {
                            $this->fail('El contenido no tiene el formato de este campo.', $sheet);
                        }
                        $values[$subject['id']][$field['key']] = $value;
                    }
                }
                if ($sheet->getHighestDataRow() >= $row || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > count($headers)) {
                    $this->fail('Hay datos fuera de las celdas de la plantilla. No agregue filas ni columnas.', $sheet);
                }
            }
            if ($values === []) {
                $this->fail('Complete al menos un campo antes de cargar el Excel. Las celdas vacías no cambian el proyecto de carrera actual.');
            }

            return array_map(function (array $subject) use ($values): array {
                $content = $values[$subject['id']] ?? [];

                return ['subject_id' => $subject['id'], 'code' => $subject['code'], 'name' => $subject['name'], 'cycle' => $subject['cycle'], 'state' => $content === [] ? 'sin_evidencia' : 'lista', 'values' => $content, 'error' => null];
            }, $definition->subjects);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** @param array<string, mixed> $field */
    private function slots(array $field): int
    {
        if ($field['columns'] === []) {
            return 1;
        }
        $table = $field['table'] ?? null;
        if (! is_array($table) || FieldResponse::listTable($table)) {
            return self::TABLE_ROWS;
        }

        return max(1, count($this->body($table)));
    }

    /** @param array<string, mixed> $table
     * @return list<array<string, mixed>>
     */
    private function body(array $table): array
    {
        return array_values(array_filter($table['content'] ?? [], fn (array $row): bool => ! array_all($row['content'] ?? [], fn (array $cell): bool => ($cell['type'] ?? '') === 'tableHeader')));
    }

    /** @param array<string, mixed> $field */
    private function fixedCell(array $field, int $row, int $column): ?string
    {
        $table = $field['table'] ?? null;
        if (! is_array($table) || FieldResponse::listTable($table)) {
            return null;
        }
        $cell = $this->body($table)[$row]['content'][$column] ?? null;

        return is_array($cell) && ! FieldResponse::blank($cell) ? trim(FreeText::plainText(['type' => 'doc', 'content' => $cell['content'] ?? []])) : null;
    }

    /** @param list<string> $columns
     * @return list<string>
     */
    private function headers(array $columns): array
    {
        return ['Referencia', 'Código', 'Asignatura', ...($columns === [] ? ['Contenido'] : $columns)];
    }

    private function assertSize(FichaWorkbookDefinition $definition): void
    {
        $cells = 0;
        foreach ($definition->fields as $field) {
            $cells += count($definition->subjects) * ($field['columns'] === [] ? 4 : (3 + count($field['columns'])) * $this->slots($field));
        }
        if ($cells > self::MAX_CELLS) {
            $this->fail('La plantilla supera el tamaño que se puede procesar en una sola carga.');
        }
    }

    private function assertArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Use un archivo Excel .xlsx válido.');
        }
        $bytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $bytes += (int) ($zip->statIndex($index)['size'] ?? 0);
        }
        $zip->close();
        if ($bytes > 150 * 1024 * 1024) {
            $this->fail('El archivo de Excel es demasiado grande para procesarlo.');
        }
    }

    /** @param array<int, array<string, mixed>> $sheets */
    private function assertDimensions(array $sheets, FichaWorkbookDefinition $definition): void
    {
        $bounds = ['Indicaciones' => [8, 2], '_estructura' => [3, 2]];
        foreach ($definition->fields as $field) {
            $bounds[$field['sheet']] = [3 + count($definition->subjects) * ($this->slots($field)), 3 + max(1, count($field['columns']))];
        }
        if (array_column($sheets, 'worksheetName') !== array_keys($bounds)) {
            $this->fail('Las hojas del Excel cambiaron. Descargue una plantilla nueva y conserve sus nombres y orden.');
        }
        foreach ($sheets as $sheet) {
            [$rows, $columns] = $bounds[$sheet['worksheetName']];
            if ($sheet['totalRows'] > $rows || $sheet['totalColumns'] > $columns) {
                $this->fail('Hay datos fuera de las celdas de la plantilla. No agregue filas ni columnas.');
            }
        }
    }

    private function cell(Worksheet $sheet, string $address): string
    {
        $cell = $sheet->getCell($address);
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            $this->fail('No se admiten fórmulas. Escriba o pegue solo el contenido.', $sheet, $address);
        }
        $value = $cell->getValue();
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if ($value !== null && ! is_scalar($value)) {
            $this->fail('La celda debe contener texto.', $sheet, $address);
        }

        return $value === null ? '' : (is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value);
    }

    private function expect(Worksheet $sheet, string $address, string $expected): void
    {
        if ($this->cell($sheet, $address) !== $expected) {
            $this->fail('No modifique los encabezados ni los datos de las materias. Descargue una plantilla nueva si cambiaron.', $sheet, $address);
        }
    }

    /** @return string|list<string> */
    private function textValue(string $text, Worksheet $sheet): string|array
    {
        $lines = array_values(array_filter(preg_split('/\R/u', $text) ?: [], fn (string $line): bool => trim($line) !== ''));
        if ($lines !== [] && array_all($lines, fn (string $line): bool => preg_match('/^\s*(?:[-*•]\s+|\d+[.)]\s+)/u', $line) === 1)) {
            if (count($lines) > 60 || array_any($lines, fn (string $line): bool => mb_strlen($line) > 3000)) {
                $this->fail('La lista admite hasta 60 elementos de 3 000 caracteres.', $sheet);
            }

            return array_map(fn (string $line): string => trim(preg_replace('/^\s*(?:[-*•]\s+|\d+[.)]\s+)/u', '', $line) ?? ''), $lines);
        }

        return $text;
    }

    private function text(Worksheet $sheet, string $address, string $text): void
    {
        $sheet->setCellValueExplicit($address, $text, DataType::TYPE_STRING);
    }

    private function fail(string $message, ?Worksheet $sheet = null, ?string $cell = null): never
    {
        $location = $sheet === null ? '' : 'Hoja «'.$sheet->getTitle().'»'.($cell === null ? '' : ', celda '.$cell).': ';

        throw ValidationException::withMessages(['file' => $location.$message]);
    }
}
