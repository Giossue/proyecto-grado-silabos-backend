<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Documents\Domain\SignatureAnchor;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use DOMDocument;
use Symfony\Component\Process\Process;
use Throwable;

class SignatureStampLocator
{
    private const WIDTH = 190.0;

    private const HEIGHT = 48.0;

    private const ANCHORED_WIDTH = 150.0;

    private const FALLBACK_WIDTH = 170.0;

    /** @return array{page: int, x: float, y: float, width: float, height: float}|null */
    public function locate(string $pdf, string $role): ?array
    {
        $pages = $this->pages($pdf);
        if ($pages === []) {
            return null;
        }

        return $this->anchored($pages, $role) ?? $this->labelled($pages, $role) ?? $this->footer($pages, $role);
    }

    /**
     * @param  list<array{width: float, height: float, words: list<array{text: string, xMin: float, xMax: float, yMin: float, yMax: float}>}>  $pages
     * @return array{page: int, x: float, y: float, width: float, height: float}|null
     */
    private function anchored(array $pages, string $role): ?array
    {
        $tokens = SignatureAnchor::words($role);
        $size = count($tokens);
        for ($page = count($pages) - 1; $page >= 0; $page--) {
            $words = $pages[$page]['words'];
            $match = null;
            for ($start = 0; $start + $size <= count($words); $start++) {
                $texts = array_column(array_slice($words, $start, $size), 'text');
                if ($texts === $tokens) {
                    $match = [$words[$start], $words[$start + $size - 1]];
                }
            }
            if ($match === null) {
                continue;
            }
            [$first, $last] = $match;
            $center = ($first['xMin'] + max($first['xMax'], $last['xMax'])) / 2;
            $x = max(20.0, min($pages[$page]['width'] - self::ANCHORED_WIDTH - 20, $center - self::ANCHORED_WIDTH / 2));

            return [
                'page' => $page,
                'x' => round($x, 2),
                'y' => round($pages[$page]['height'] - $first['yMin'] + 2, 2),
                'width' => self::ANCHORED_WIDTH,
                'height' => self::HEIGHT,
            ];
        }

        return null;
    }

    /**
     * @param  list<array{width: float, height: float, words: list<array{text: string, xMin: float, xMax: float, yMin: float, yMax: float}>}>  $pages
     * @return array{page: int, x: float, y: float, width: float, height: float}|null
     */
    private function labelled(array $pages, string $role): ?array
    {
        $index = $this->order($role);
        for ($page = count($pages) - 1; $page >= 0; $page--) {
            $labels = array_values(array_filter($pages[$page]['words'], fn (array $word): bool => $word['text'] === 'Firma:'));
            if (count($labels) < 1) {
                continue;
            }
            usort($labels, fn (array $a, array $b): int => [round($a['yMin'] / 4), $a['xMin']] <=> [round($b['yMin'] / 4), $b['xMin']]);
            if (! isset($labels[$index])) {
                return null;
            }
            $label = $labels[$index];

            return [
                'page' => $page,
                'x' => round($label['xMin'] + 4, 2),
                'y' => round($pages[$page]['height'] - $label['yMin'] + 2, 2),
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
            ];
        }

        return null;
    }

    /**
     * @param  list<array{width: float, height: float, words: list<array{text: string, xMin: float, xMax: float, yMin: float, yMax: float}>}>  $pages
     * @return array{page: int, x: float, y: float, width: float, height: float}
     */
    private function footer(array $pages, string $role): array
    {
        $last = count($pages) - 1;
        $bottom = 0.0;
        foreach ($pages[$last]['words'] as $word) {
            $bottom = max($bottom, $word['yMax']);
        }
        $width = $pages[$last]['width'];
        $y = max(36.0, $pages[$last]['height'] - $bottom - 16 - self::HEIGHT);
        $x = match ($this->order($role)) {
            0 => 30.0,
            1 => ($width - self::FALLBACK_WIDTH) / 2,
            default => $width - self::FALLBACK_WIDTH - 30,
        };

        return [
            'page' => $last,
            'x' => round($x, 2),
            'y' => round($y, 2),
            'width' => self::FALLBACK_WIDTH,
            'height' => self::HEIGHT,
        ];
    }

    private function order(string $role): int
    {
        $index = array_search($role, DocumentSignature::ORDER, true);

        return $index === false ? 0 : $index;
    }

    /** @return list<array{width: float, height: float, words: list<array{text: string, xMin: float, xMax: float, yMin: float, yMax: float}>}> */
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

    /** @return list<array{width: float, height: float, words: list<array{text: string, xMin: float, xMax: float, yMin: float, yMax: float}>}> */
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
                    'xMax' => (float) $word->getAttribute('xMax'),
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
