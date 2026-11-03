<?php

namespace App\Modules\Identity\Application\Spreadsheet;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Throwable;
use ZipArchive;

class SpreadsheetReader
{
    public const MAX_ROWS = 500;

    /** Filas que se recorren como máximo, vacías incluidas: encabezado, MAX_ROWS y un margen. */
    public const MAX_SCANNED_ROWS = self::MAX_ROWS + 100;

    public const MAX_COLUMNS = 64;

    private const MAX_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;

    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $optional  columnas que un archivo antiguo puede no traer; llegan vacías
     * @return list<array{line: int, values: array<string, string>}>
     */
    public function read(UploadedFile $file, array $columns, array $optional = []): array
    {
        $this->assertArchive((string) $file->getRealPath());
        $reader = new Reader;
        $rows = [];
        $map = null;
        $scanned = 0;

        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    if (++$scanned > self::MAX_SCANNED_ROWS) {
                        throw ValidationException::withMessages(['file' => 'El archivo tiene demasiadas filas, contando las vacías. Use hasta '.self::MAX_ROWS.' filas y borre las filas sobrantes.']);
                    }
                    $cells = array_map(fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : '', array_slice($row->toArray(), 0, self::MAX_COLUMNS));
                    if ($map === null) {
                        $map = $this->headerMap($cells, $columns, $optional);

                        continue;
                    }
                    $values = [];
                    foreach ($map as $key => $position) {
                        $values[$key] = $position === null ? '' : ($cells[$position] ?? '');
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
     * Revisa el archivo antes de leer filas: tamaño descomprimido y ancho declarado de cada hoja.
     * Así un .xlsx pequeño no obliga a recorrer miles de columnas o filas vacías.
     */
    private function assertArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo. Use la plantilla .xlsx.']);
        }
        try {
            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $bytes += (int) ($stat['size'] ?? 0);
                $name = (string) ($stat['name'] ?? '');
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name) === 1 && $this->declaredColumns($zip, $name) > self::MAX_COLUMNS) {
                    throw ValidationException::withMessages(['file' => 'El archivo tiene más de '.self::MAX_COLUMNS.' columnas. Use la plantilla .xlsx.']);
                }
            }
            if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw ValidationException::withMessages(['file' => 'El archivo es demasiado grande para procesarlo. Use la plantilla .xlsx.']);
            }
        } finally {
            $zip->close();
        }
    }

    /** Última columna de la etiqueta `<dimension>` de la hoja; 0 si no la declara. */
    private function declaredColumns(ZipArchive $zip, string $name): int
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return 0;
        }
        $head = (string) fread($stream, 16384);
        fclose($stream);
        if (preg_match('/<(?:\w+:)?dimension\b[^>]*\bref="(?:[A-Z]+\d+:)?([A-Z]+)\d+"/i', $head, $match) !== 1) {
            return 0;
        }
        $column = strtoupper($match[1]);

        return strlen($column) > 3 ? PHP_INT_MAX : Coordinate::columnIndexFromString($column);
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, string>  $columns
     * @param  list<string>  $optional
     * @return array<string, int|null>
     */
    private function headerMap(array $cells, array $columns, array $optional): array
    {
        $normalized = array_map(fn (string $cell): string => $this->normalize($cell), $cells);
        $map = [];
        foreach ($columns as $key => $header) {
            $position = array_search($this->normalize($header), $normalized, true);
            if ($position === false && in_array($key, $optional, true)) {
                $map[$key] = null;

                continue;
            }
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
