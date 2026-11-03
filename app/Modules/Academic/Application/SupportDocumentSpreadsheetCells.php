<?php

namespace App\Modules\Academic\Application;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Cuenta las celdas con datos de una hoja de cálculo de apoyo sin cargar el libro en memoria.
 * Se detiene en cuanto pasa el límite: el resultado nunca es mayor que MAX_CELLS + 1.
 */
class SupportDocumentSpreadsheetCells
{
    public const MAX_CELLS = 5000;

    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    private const TOO_LARGE_CODE = 1;

    private const LIMIT_REACHED_CODE = 2;

    public const TOO_MANY = 'La hoja de cálculo tiene más de 5.000 celdas con datos. Divídala en archivos más pequeños o súbala como PDF.';

    public const TOO_LARGE = 'La hoja de cálculo es demasiado grande para procesarla. Súbala como PDF.';

    public const UNREADABLE = 'No se pudo leer la hoja de cálculo. Revise que el archivo no esté dañado.';

    /**
     * Devuelve el mensaje de error para el archivo, o null si se puede aceptar.
     */
    public function problem(string $path, string $extension): ?string
    {
        try {
            $cells = match (strtolower($extension)) {
                'xlsx' => $this->xlsx($path),
                'xls' => $this->xls($path),
                default => 0,
            };
        } catch (RuntimeException $exception) {
            return $exception->getCode() === self::TOO_LARGE_CODE ? self::TOO_LARGE : self::UNREADABLE;
        } catch (Throwable) {
            return self::UNREADABLE;
        }

        return $cells > self::MAX_CELLS ? self::TOO_MANY : null;
    }

    /**
     * Recorre cada hoja del .xlsx con XMLReader (en flujo) y cuenta los valores `<v>` y los textos
     * en línea `<is>`. Las celdas vacías con solo formato no cuentan.
     */
    private function xlsx(string $path): int
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No es un .xlsx.');
        }
        $sheets = [];
        $bytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $bytes += (int) ($stat['size'] ?? 0);
            $name = (string) ($stat['name'] ?? '');
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name) === 1) {
                $sheets[] = $name;
            }
        }
        $zip->close();
        if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('Libro demasiado grande.', self::TOO_LARGE_CODE);
        }
        if ($sheets === []) {
            throw new RuntimeException('El libro no tiene hojas.');
        }

        $cells = 0;
        foreach ($sheets as $sheet) {
            $xml = new XMLReader;
            if (! $xml->open('zip://'.$path.'#'.$sheet, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Hoja ilegible.');
            }
            try {
                while ($xml->read()) {
                    if ($xml->nodeType === XMLReader::ELEMENT && ! $xml->isEmptyElement && in_array($xml->localName, ['v', 'is'], true)
                        && ++$cells > self::MAX_CELLS) {
                        return $cells;
                    }
                }
            } finally {
                $xml->close();
            }
        }

        return $cells;
    }

    /**
     * Lee el .xls registro por registro con un filtro que cuenta cada celda con datos y no guarda
     * ninguna. Los registros BLANK (celdas vacías con formato) se saltan.
     */
    private function xls(string $path): int
    {
        $filter = new class(self::MAX_CELLS, self::LIMIT_REACHED_CODE) implements IReadFilter
        {
            /** @var array<string, true> */
            public array $seen = [];

            public function __construct(private readonly int $max, private readonly int $code) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                $this->seen["{$worksheetName}!{$columnAddress}{$row}"] = true;
                if (count($this->seen) > $this->max) {
                    throw new RuntimeException('Límite de celdas alcanzado.', $this->code);
                }

                return false;
            }
        };
        $reader = new class extends Xls
        {
            protected function readBlank(): void
            {
                $this->pos += 4 + self::getUInt2d($this->data, $this->pos + 2);
            }
        };
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter($filter);
        try {
            $reader->load($path);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== self::LIMIT_REACHED_CODE) {
                throw $exception;
            }

            return self::MAX_CELLS + 1;
        }

        return count($filter->seen);
    }
}
