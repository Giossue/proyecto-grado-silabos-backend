<?php

namespace App\Modules\Identity\Application\Spreadsheet;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

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
                    $cells = array_map(fn (mixed $cell): string => trim((string) $cell), $row->toArray());
                    if ($map === null) {
                        $map = $this->headerMap($cells, $columns);

                        continue;
                    }
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    $values = [];
                    foreach ($map as $key => $position) {
                        $values[$key] = $cells[$position] ?? '';
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
