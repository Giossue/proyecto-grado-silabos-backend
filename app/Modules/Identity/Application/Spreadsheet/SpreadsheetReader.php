<?php

namespace App\Modules\Identity\Application\Spreadsheet;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

class SpreadsheetReader
{
    public const MAX_ROWS = 500;

    /**
     * @param  array<string, string>  $columns  clave interna => encabezado esperado
     * @return list<array{line: int, values: array<string, string>}>
     */
    public function read(UploadedFile $file, array $columns): array
    {
        $reader = new Reader;
        $rows = [];
        $map = null;

        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $cells = array_values(array_map(fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : '', $row->toArray()));
                    if ($map === null) {
                        $map = $this->headerMap($cells, $columns);

                        continue;
                    }
                    $values = [];
                    foreach ($map as $key => $position) {
                        $values[$key] = $cells[$position] ?? '';
                    }
                    if (implode('', $values) === '') {
                        continue;
                    }
                    $rows[] = ['line' => (int) $index, 'values' => $values];
                    if (count($rows) > self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => 'El archivo supera '.self::MAX_ROWS.' filas.']);
                    }
                }
                break;
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo. Use la plantilla .xlsx.']);
        } finally {
            $reader->close();
        }

        if ($map === null || $rows === []) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene filas para importar.']);
        }

        return $rows;
    }

    /**
     * Lee la primera hoja con PhpSpreadsheet y asigna cada imagen a la fila donde cae su centro.
     *
     * @param  array<string, string>  $columns
     * @return list<array{line: int, values: array<string, string>, images: list<string>}>
     */
    public function readWithImages(UploadedFile $file, array $columns, int $maxRows = 50): array
    {
        $path = $file->getRealPath();
        $zip = new ZipArchive;
        $inCell = $zip->open($path) === true && $zip->locateName('xl/richData/rdrichvalue.xml') !== false;
        $zip->close();

        try {
            $sheet = IOFactory::load($path)->getSheet(0);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo. Use la plantilla .xlsx.']);
        }

        $images = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            $bytes = $this->imageBytes($drawing);
            if ($bytes === null) {
                continue;
            }
            $images[$this->centerRow($sheet, $drawing)][] = $bytes;
        }
        if ($images === [] && $inCell) {
            throw ValidationException::withMessages(['file' => 'Las imágenes están dentro de las celdas. Péguelas con «Colocar sobre las celdas».']);
        }

        $rows = [];
        $map = null;
        foreach ($sheet->toArray(null, true, false, false) as $index => $raw) {
            $cells = array_values(array_map(fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : '', $raw));
            $line = $index + 1;
            if ($map === null) {
                $map = $this->headerMap($cells, $columns);

                continue;
            }
            $values = [];
            foreach ($map as $key => $position) {
                $values[$key] = $cells[$position] ?? '';
            }
            $rowImages = $images[$line] ?? [];
            if (implode('', $values) === '' && $rowImages === []) {
                continue;
            }
            $rows[] = ['line' => $line, 'values' => $values, 'images' => $rowImages];
            if (count($rows) > $maxRows) {
                throw ValidationException::withMessages(['file' => "El archivo supera {$maxRows} filas."]);
            }
        }
        if ($map === null || $rows === []) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene filas para importar.']);
        }

        return $rows;
    }

    private function imageBytes(BaseDrawing $drawing): ?string
    {
        if ($drawing instanceof Drawing) {
            $bytes = @file_get_contents($drawing->getPath());

            return $bytes === false ? null : $bytes;
        }
        if ($drawing instanceof MemoryDrawing) {
            ob_start();
            imagepng($drawing->getImageResource());

            return (string) ob_get_clean();
        }

        return null;
    }

    private function centerRow(Worksheet $sheet, BaseDrawing $drawing): int
    {
        $start = (int) preg_replace('/\D/', '', $drawing->getCoordinates());
        $center = $drawing->getOffsetY() + $drawing->getHeight() / 2;
        $row = $start;
        $top = 0.0;
        while ($row < $start + 500) {
            $points = $sheet->getRowDimension($row)->getRowHeight();
            $height = ($points > 0 ? $points : $sheet->getDefaultRowDimension()->getRowHeight()) * 96 / 72;
            $height = $height > 0 ? $height : 20.0;
            if ($center < $top + $height) {
                return $row;
            }
            $top += $height;
            $row++;
        }

        return $start;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, string>  $columns
     * @return array<string, int>
     */
    private function headerMap(array $cells, array $columns): array
    {
        $normalized = array_map(fn (string $cell): string => $this->normalize($cell), $cells);
        $map = [];
        foreach ($columns as $key => $header) {
            $position = array_search($this->normalize($header), $normalized, true);
            if ($position === false) {
                throw ValidationException::withMessages(['file' => "Falta la columna «{$header}». Use la plantilla .xlsx."]);
            }
            $map[$key] = (int) $position;
        }

        return $map;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }
}
