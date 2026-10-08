<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Gateways\OpenParserPdfReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/** Convierte un PDF (con el OCR de OpenParser si está configurado) o un Excel de la carrera en fragmentos de texto para buscar evidencia por materia. */
final class FichaDocumentText
{
    private const BLOCK = 1800;

    private const MAX_SHEETS = 20;

    private const MAX_ROWS = 5000;

    private const MAX_UNCOMPRESSED_BYTES = 150 * 1024 * 1024;

    public function __construct(private readonly OpenParserPdfReader $ocr) {}

    /** @return list<string> */
    public function blocks(string $absolutePath, string $name): array
    {
        $text = match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'pdf' => $this->ocr->available() ? $this->ocr->text($absolutePath, $name) : $this->pdf($absolutePath),
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

    private function assertSafeArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $total += (int) ($zip->statIndex($index)['size'] ?? 0);
        }
        $zip->close();
        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('El archivo de Excel es demasiado grande para procesarlo.');
        }
    }

    private function workbook(string $path): string
    {
        $this->assertSafeArchive($path);
        $type = IOFactory::identify($path, [IOFactory::READER_XLSX, IOFactory::READER_XLS]);
        $reader = IOFactory::createReader($type);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $lines = [];
        foreach (array_slice($book->getAllSheets(), 0, self::MAX_SHEETS) as $sheet) {
            $lines[] = '## '.$sheet->getTitle();
            foreach (array_slice($sheet->toArray(null, true, true, false), 0, self::MAX_ROWS) as $row) {
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
