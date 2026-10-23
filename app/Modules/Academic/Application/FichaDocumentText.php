<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Gateways\OpenParserPdfReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use ZipArchive;

final class FichaDocumentText
{
    private const BLOCK = 1800;

    private const MAX_SHEETS = 20;

    private const MAX_ROWS = 5000;

    private const MAX_UNCOMPRESSED_BYTES = 150 * 1024 * 1024;

    public function __construct(private readonly OpenParserPdfReader $ocr) {}

    /**
     * @param  (callable(string): void)|null  $onOcrStatus
     * @return list<string>
     */
    public function blocks(string $absolutePath, string $name, ?callable $onOcrStatus = null): array
    {
        return array_column($this->read($absolutePath, $name, $onOcrStatus)['blocks'], 'text');
    }

    /**
     * @param  (callable(string): void)|null  $onStatus
     * @return array{blocks: list<array{text: string, document: string, page: int|null}>, method: string, duration_ms: int}
     */
    public function read(string $absolutePath, string $name, ?callable $onStatus = null): array
    {
        $started = microtime(true);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $method = $extension === 'pdf' ? 'ocr' : 'excel';
        $text = match ($extension) {
            'pdf' => $this->pdfText($absolutePath, $name, $onStatus),
            'xlsx', 'xls' => $this->workbook($absolutePath),
            default => throw new RuntimeException('Solo se admiten archivos PDF o Excel.'),
        };
        $blocks = [];
        foreach ($this->pages($text) as $content) {
            foreach ($this->split($content) as $piece) {
                $blocks[] = ['text' => $piece, 'document' => $name, 'page' => null];
            }
        }

        return ['blocks' => $blocks, 'method' => $method, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    /** @param (callable(string): void)|null $onStatus */
    private function pdfText(string $path, string $name, ?callable $onStatus): string
    {
        if (! $this->ocr->available()) {
            throw new RuntimeException('El servicio OCR no está configurado. Pídale a Administración que lo active para importar PDF.');
        }

        return $this->ocr->text($path, $name, $onStatus);
    }

    /** @return list<string> */
    private function pages(string $text): array
    {
        $text = rtrim($text, "\r\n");
        if (str_ends_with($text, "\f")) {
            $text = substr($text, 0, -1);
        }

        return explode("\f", $text);
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
