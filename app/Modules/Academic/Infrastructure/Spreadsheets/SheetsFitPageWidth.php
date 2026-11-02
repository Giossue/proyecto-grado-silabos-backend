<?php

namespace App\Modules\Academic\Infrastructure\Spreadsheets;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

final class SheetsFitPageWidth
{
    private const LANDSCAPE_FROM_COLUMNS = 8;

    public function apply(string $content, string $extension): string
    {
        $source = $this->temporary($content, $extension);
        $target = $this->temporary('', 'xlsx');
        try {
            $book = IOFactory::load($source);
            try {
                foreach ($book->getWorksheetIterator() as $sheet) {
                    $setup = $sheet->getPageSetup();
                    $setup->setFitToWidth(1)->setFitToHeight(0);
                    if (Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > self::LANDSCAPE_FROM_COLUMNS) {
                        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                    }
                }
                (new Xlsx($book))->setPreCalculateFormulas(false)->save($target);
            } finally {
                $book->disconnectWorksheets();
            }

            return (string) file_get_contents($target);
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo ajustar el Excel al ancho de la página.', previous: $exception);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    private function temporary(string $content, string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'silabos-apoyo-');
        if ($base === false) {
            throw new RuntimeException('No se pudo preparar el archivo temporal.');
        }
        $path = "{$base}.{$extension}";
        rename($base, $path);
        file_put_contents($path, $content);

        return $path;
    }
}
