<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\SimpleType\TblWidth;

final class TemplateDocumentWord
{
    private const TABLE_BORDER = '000000';

    private const TABLE_MIN_FONT_SIZE = 7;

    private const CELL_MARGINS = 100;

    private ?int $tableFontSize = null;

    public function __construct(
        private readonly ?string $paragraphAlignment = null,
        private readonly ?string $tableHeaderBackground = null,
        private readonly ?string $tableHeaderColor = null,
        private readonly int $bodyFontSize = 11,
    ) {}

    /** @param array<string, mixed> $node */
    public function append(
        AbstractContainer $target,
        array $node,
        int $width = 9406,
        ?string $list = null,
        ?string $alignmentOverride = null,
        bool $insideTable = false,
    ): void {
        if ($node['type'] === 'table') {
            $this->table($target, $node, $width);

            return;
        }
        if ($node['type'] === 'paragraph') {
            $alignment = $alignmentOverride ?? $this->paragraphAlignment ?? ($node['attrs']['textAlign'] ?? 'left');
            $style = ['spaceAfter' => $insideTable ? 0 : 80, 'alignment' => $alignment === 'justify' ? 'both' : $alignment];
            if ($list !== null) {
                $style['numStyle'] = $list;
                $style['numLevel'] = 0;
            }
            $run = $target->addTextRun($style);
            foreach ($node['content'] ?? [] as $text) {
                if ($text['type'] === 'hardBreak') {
                    $run->addTextBreak();

                    continue;
                }
                $font = $insideTable && $this->tableFontSize !== null ? ['size' => $this->tableFontSize] : [];
                foreach ($text['marks'] ?? [] as $mark) {
                    if (in_array($mark['type'], ['bold', 'italic'], true)) {
                        $font[$mark['type']] = true;
                    } elseif ($mark['type'] === 'underline') {
                        $font['underline'] = 'single';
                    } elseif ($mark['type'] === 'textStyle') {
                        $attrs = $mark['attrs'];
                        if (isset($attrs['color'])) {
                            $font['color'] = ltrim($attrs['color'], '#');
                        }
                        if (isset($attrs['fontFamily']) && ! $insideTable) {
                            $font['name'] = $attrs['fontFamily'];
                        }
                    }
                }
                $lines = explode("\n", $text['text'] ?? '');
                foreach ($lines as $index => $line) {
                    if ($index > 0) {
                        $run->addTextBreak();
                    }
                    $run->addText($line, $font);
                }
            }

            return;
        }
        $list = match ($node['type']) {
            'bulletList' => 'silabo-bullets', 'orderedList' => 'silabo-numbers', default => $list
        };
        foreach ($node['content'] ?? [] as $child) {
            $this->append($target, $child, $width, $list, $alignmentOverride, $insideTable);
        }
    }

    /** @param array<string, mixed> $node */
    private function table(AbstractContainer $target, array $node, int $width): void
    {
        $rows = $node['content'];
        $count = array_sum(array_map(fn ($cell) => $cell['attrs']['colspan'] ?? 1, $rows[0]['content']));
        $weights = array_fill(0, $count, 100);
        $occupied = [];
        foreach ($rows as $r => $row) {
            $column = 0;
            foreach ($row['content'] as $cell) {
                while (isset($occupied[$r][$column])) {
                    $column++;
                }
                $span = $cell['attrs']['colspan'] ?? 1;
                for ($c = 0; $c < $span; $c++) {
                    if (($cell['attrs']['colwidth'][$c] ?? 0) > 0) {
                        $weights[$column + $c] = $cell['attrs']['colwidth'][$c];
                    }
                    for ($y = 1; $y < ($cell['attrs']['rowspan'] ?? 1); $y++) {
                        $occupied[$r + $y][$column + $c] = true;
                    }
                }
                $column += $span;
            }
        }
        $widths = array_values(array_map(fn ($weight) => (int) round($width * $weight / array_sum($weights)), $weights));
        $outerFontSize = $this->tableFontSize;
        $cellTexts = $this->cellTexts($rows, $widths);
        $tableFontSize = $this->fontSizeThatFits($cellTexts);
        $rowHeights = [];
        foreach ($cellTexts as $cellText) {
            if ($cellText['header'] && $this->rotates($cellText['text'], $cellText['width'], $tableFontSize ?? $this->bodyFontSize)) {
                $needed = (int) ceil((self::wordWidth($this->longestWord($cellText['text']), $tableFontSize ?? $this->bodyFontSize) + self::CELL_MARGINS) / $cellText['rows']);
                for ($y = 0; $y < $cellText['rows']; $y++) {
                    $rowHeights[$cellText['row'] + $y] = max($rowHeights[$cellText['row'] + $y] ?? 0, $needed);
                }
            }
        }
        $table = $target->addTable(['borderSize' => 4, 'borderColor' => self::TABLE_BORDER, 'cellMargin' => 50, 'width' => 5000, 'unit' => TblWidth::PERCENT]);
        $merged = [];
        foreach ($rows as $rowIndex => $source) {
            $isRepeatingHeader = array_reduce(
                $source['content'],
                fn (bool $carry, array $cell): bool => $carry && $cell['type'] === 'tableHeader',
                true,
            );
            $row = $table->addRow($rowHeights[$rowIndex] ?? null, $isRepeatingHeader ? ['tblHeader' => true] : []);
            $cells = $source['content'];
            $column = 0;
            while ($column < $count) {
                if (isset($merged[$column])) {
                    $merge = $merged[$column];
                    $row->addCell(
                        (int) array_sum(array_slice($widths, $column, $merge['span'])),
                        $merge['options'],
                    );
                    if (--$merged[$column]['rows'] === 0) {
                        unset($merged[$column]);
                    }
                    $column += $merge['span'];

                    continue;
                }
                $cell = array_shift($cells);
                if ($cell === null) {
                    break;
                }
                $attrs = $cell['attrs'];
                $span = $attrs['colspan'] ?? 1;
                $height = $attrs['rowspan'] ?? 1;
                $cellWidth = (int) array_sum(array_slice($widths, $column, $span));
                $isHeader = $cell['type'] === 'tableHeader';
                $options = [
                    'gridSpan' => $span,
                    'valign' => 'center',
                    ...$this->cellBorderOptions($attrs),
                ];
                if ($isHeader && $this->tableHeaderBackground !== null) {
                    $options['bgColor'] = $this->tableHeaderBackground;
                }
                if (($attrs['backgroundColor'] ?? null) !== null) {
                    $options['bgColor'] = ltrim($attrs['backgroundColor'], '#');
                }
                if ($height > 1) {
                    $options['vMerge'] = 'restart';
                    $merged[$column] = [
                        'span' => $span,
                        'rows' => $height - 1,
                        'options' => [...$options, 'vMerge' => 'continue'],
                    ];
                }
                $available = $cellWidth - self::CELL_MARGINS;
                $longest = $this->longestWord($this->plainText($cell));
                $this->tableFontSize = $tableFontSize;
                if ($isHeader && $this->rotates($this->plainText($cell), $cellWidth, $tableFontSize ?? $this->bodyFontSize)) {
                    $options['textDirection'] = 'btLr';
                } elseif (self::wordWidth($longest, $tableFontSize ?? $this->bodyFontSize) > $available) {
                    $this->tableFontSize = self::TABLE_MIN_FONT_SIZE - 1;
                }
                $destination = $row->addCell($cellWidth, $options);
                foreach ($cell['content'] as $child) {
                    $styled = $isHeader && $this->tableHeaderColor !== null
                        ? $this->withTextColor($child, $this->tableHeaderColor)
                        : $child;
                    $this->append(
                        $destination,
                        $this->withCellTextStyle($styled, $attrs),
                        $cellWidth,
                        null,
                        is_string($attrs['textAlign'] ?? null) ? $attrs['textAlign'] : null,
                        true,
                    );
                }
                $column += $span;
            }
        }
        $this->tableFontSize = $outerFontSize;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $widths
     * @return list<array{text: string, width: int, row: int, rows: int, header: bool}>
     */
    private function cellTexts(array $rows, array $widths): array
    {
        $texts = [];
        $occupied = [];
        foreach ($rows as $r => $row) {
            $column = 0;
            foreach ($row['content'] as $cell) {
                while (isset($occupied[$r][$column])) {
                    $column++;
                }
                $span = $cell['attrs']['colspan'] ?? 1;
                for ($y = 1; $y < ($cell['attrs']['rowspan'] ?? 1); $y++) {
                    for ($c = 0; $c < $span; $c++) {
                        $occupied[$r + $y][$column + $c] = true;
                    }
                }
                $texts[] = [
                    'text' => $this->plainText($cell),
                    'width' => (int) array_sum(array_slice($widths, $column, $span)),
                    'row' => $r,
                    'rows' => max(1, (int) ($cell['attrs']['rowspan'] ?? 1)),
                    'header' => $cell['type'] === 'tableHeader',
                ];
                $column += $span;
            }
        }

        return $texts;
    }

    /** @param  list<array{text: string, width: int, row: int, rows: int, header: bool}>  $cells */
    private function fontSizeThatFits(array $cells): ?int
    {
        $cells = array_filter(
            $cells,
            fn (array $cell): bool => self::wordWidth($this->longestWord($cell['text']), self::TABLE_MIN_FONT_SIZE) <= $cell['width'] - self::CELL_MARGINS,
        );
        for ($size = $this->bodyFontSize; $size > self::TABLE_MIN_FONT_SIZE; $size--) {
            $fits = true;
            foreach ($cells as $cell) {
                if (self::wordWidth($this->longestWord($cell['text']), $size) > $cell['width'] - self::CELL_MARGINS) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                return $size === $this->bodyFontSize ? null : $size;
            }
        }

        return self::TABLE_MIN_FONT_SIZE;
    }

    private function rotates(string $text, int $width, int $tableFontSize): bool
    {
        $longest = $this->longestWord($text);
        $available = $width - self::CELL_MARGINS;

        return self::wordWidth($longest, $tableFontSize) > $available
            && self::wordWidth($longest, self::TABLE_MIN_FONT_SIZE - 1) > $available;
    }

    private function longestWord(string $text): string
    {
        $longest = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            if (self::wordWidth($word, 10) > self::wordWidth($longest, 10)) {
                $longest = $word;
            }
        }

        return $longest;
    }

    public static function wordWidth(string $word, int $size): int
    {
        $em = $size * 20;
        $width = 0.0;
        foreach (mb_str_split($word) as $character) {
            $width += match (true) {
                in_array($character, ['I', 'i', 'l', 'j', '.', ',', ':', ';', '|', '!', "'"], true) => 0.3,
                in_array($character, ['M', 'W', 'm', 'w'], true) => 0.9,
                mb_strtoupper($character) === $character && mb_strtolower($character) !== $character => 0.74,
                default => 0.58,
            };
        }

        return (int) ceil($width * $em);
    }

    /** @param array<string, mixed> $node */
    private function plainText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) ($node['text'] ?? '');
        }
        $parts = [];
        foreach ($node['content'] ?? [] as $child) {
            $parts[] = $this->plainText($child);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, int|string>
     */
    private function cellBorderOptions(array $attrs): array
    {
        return match ($attrs['borderStyle'] ?? null) {
            'none' => ['borderSize' => 0, 'borderStyle' => 'none'],
            'thick' => ['borderSize' => 12, 'borderStyle' => 'single', 'borderColor' => self::TABLE_BORDER],
            'thin' => ['borderSize' => 4, 'borderStyle' => 'single', 'borderColor' => self::TABLE_BORDER],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function withCellTextStyle(array $node, array $attrs): array
    {
        if ($node['type'] === 'paragraph' && is_string($attrs['textAlign'] ?? null)) {
            $node['attrs'] = [
                ...($node['attrs'] ?? []),
                'textAlign' => $attrs['textAlign'],
            ];
        }

        if (in_array($node['type'], ['text', 'variable', 'field', 'column'], true)) {
            $marks = $node['marks'] ?? [];
            foreach (['bold', 'italic'] as $markType) {
                if (! is_bool($attrs[$markType] ?? null)) {
                    continue;
                }

                $marks = array_values(array_filter(
                    $marks,
                    fn (array $mark): bool => $mark['type'] !== $markType,
                ));
                if ($attrs[$markType]) {
                    $marks[] = ['type' => $markType];
                }
            }
            $node['marks'] = $marks;

            if (is_string($attrs['textColor'] ?? null)) {
                $node = $this->withTextColor($node, ltrim($attrs['textColor'], '#'));
            }
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(
                fn (array $child): array => $this->withCellTextStyle($child, $attrs),
                $node['content'],
            );
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withTextColor(array $node, string $color): array
    {
        if ($node['type'] === 'text') {
            $marks = $node['marks'] ?? [];
            $hasTextStyle = false;
            foreach ($marks as $index => $mark) {
                if ($mark['type'] !== 'textStyle') {
                    continue;
                }

                $marks[$index]['attrs'] = [
                    ...($mark['attrs'] ?? []),
                    'color' => '#'.$color,
                ];
                $hasTextStyle = true;
            }
            if (! $hasTextStyle) {
                $marks[] = ['type' => 'textStyle', 'attrs' => ['color' => '#'.$color]];
            }
            $node['marks'] = $marks;
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(
                fn (array $child): array => $this->withTextColor($child, $color),
                $node['content'],
            );
        }

        return $node;
    }
}
