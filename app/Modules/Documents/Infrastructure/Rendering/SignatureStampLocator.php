<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use DOMDocument;
use Symfony\Component\Process\Process;
use Throwable;

class SignatureStampLocator
{
    private const WIDTH = 190.0;

    private const HEIGHT = 48.0;

    /** @return array{page: int, x: float, y: float, width: float, height: float}|null */
    public function locate(string $pdf, string $role): ?array
    {
        $pages = $this->pages($pdf);
        if ($pages === []) {
            return null;
        }
        $index = $role === DocumentSignature::ROLE_COORDINATION ? 1 : 0;

        for ($page = count($pages) - 1; $page >= 0; $page--) {
            $labels = array_values(array_filter($pages[$page]['words'], fn (array $word): bool => $word['text'] === 'Firma:'));
            if (count($labels) < 1) {
                continue;
            }
            usort($labels, fn (array $a, array $b): int => [round($a['yMin'] / 4), $a['xMin']] <=> [round($b['yMin'] / 4), $b['xMin']]);
            if (! isset($labels[$index])) {
                break;
            }
            $label = $labels[$index];
            $height = $pages[$page]['height'];

            return [
                'page' => $page,
                'x' => round($label['xMin'] + 4, 2),
                'y' => round($height - $label['yMin'] + 2, 2),
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
            ];
        }

        $last = count($pages) - 1;
        $bottom = 0.0;
        foreach ($pages[$last]['words'] as $word) {
            $bottom = max($bottom, $word['yMax']);
        }
        $height = $pages[$last]['height'];
        $y = max(36.0, $height - $bottom - 16 - self::HEIGHT);

        return [
            'page' => $last,
            'x' => $index === 0 ? 60.0 : round($pages[$last]['width'] / 2 + 10, 2),
            'y' => round($y, 2),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
        ];
    }

    /** @return list<array{width: float, height: float, words: list<array{text: string, xMin: float, yMin: float, yMax: float}>}> */
    private function pages(string $pdf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'silabos-sello-');
        if ($file === false) {
            return [];
        }
        try {
            file_put_contents($file, $pdf);
            $process = new Process(['pdftotext', '-bbox', '-q', $file, '-']);
            $process->setTimeout(20);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }

            return $this->parse($process->getOutput());
        } catch (Throwable) {
            return [];
        } finally {
            @unlink($file);
        }
    }

    /** @return list<array{width: float, height: float, words: list<array{text: string, xMin: float, yMin: float, yMax: float}>}> */
    private function parse(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $pages = [];
        foreach ($document->getElementsByTagName('page') as $page) {
            $words = [];
            foreach ($page->getElementsByTagName('word') as $word) {
                $words[] = [
                    'text' => trim($word->textContent),
                    'xMin' => (float) $word->getAttribute('xMin'),
                    'yMin' => (float) $word->getAttribute('yMin'),
                    'yMax' => (float) $word->getAttribute('yMax'),
                ];
            }
            $pages[] = [
                'width' => (float) $page->getAttribute('width'),
                'height' => (float) $page->getAttribute('height'),
                'words' => $words,
            ];
        }

        return $pages;
    }
}
