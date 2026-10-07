<?php

namespace App\Modules\Academic\Application;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Convierte un PDF o un Excel de la carrera en fragmentos de texto para buscar evidencia por materia. */
final class FichaDocumentText
{
    private const BLOCK = 1800;

    /** @return list<string> */
    public function blocks(string $absolutePath, string $name): array
    {
        $text = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => $this->pdf($absolutePath),
            'xlsx', 'xls' => $this->workbook($absolutePath),
            default => throw new RuntimeException('Solo se admiten archivos PDF o Excel.'),
        };

        return $this->split($text);
    }

    private function pdf(string $path): string
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(90);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('No se pudo leer el PDF.');
        }

        return $process->getOutput();
    }

    private function workbook(string $path): string
    {
        $book = IOFactory::load($path);
        $lines = [];
        foreach ($book->getAllSheets() as $sheet) {
            $lines[] = '## '.$sheet->getTitle();
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $cells = array_filter(array_map(fn (mixed $cell): string => trim(preg_replace('/\s+/u', ' ', (string) $cell) ?? ''), $row), fn (string $cell): bool => $cell !== '');
                if ($cells !== []) {
                    $lines[] = implode(' | ', $cells);
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function split(string $text): array
    {
        $blocks = [];
        $current = '';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = rtrim(preg_replace('/[ \t]{2,}/u', '  ', $line) ?? '');
            if ($line === '' && $current === '') {
                continue;
            }
            $current .= $line."\n";
            if (mb_strlen($current) >= self::BLOCK) {
                $blocks[] = trim($current);
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $blocks[] = trim($current);
        }

        return $blocks;
    }
}
