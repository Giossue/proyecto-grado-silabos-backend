<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\SimpleType\TblWidth;

final class TemplateDocumentWord
{
    private int $listDepth = 0;

    private bool $keepWithNext = false;

    public function __construct(
        private readonly ?string $paragraphAlignment = null,
        private readonly ?string $tableHeaderBackground = null,
        private readonly ?string $tableHeaderColor = null,
        private readonly string $tableBorder = '333333',
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
            $own = $node['attrs']['textAlign'] ?? null;
            $alignment = $alignmentOverride
                ?? (is_string($own) && $own !== 'left' ? $own : null)
                ?? $this->paragraphAlignment
                ?? (is_string($own) ? $own : 'left');
            $style = ['spaceAfter' => $insideTable ? 0 : 80, 'alignment' => $alignment === 'justify' ? 'both' : $alignment];
            if ($insideTable && $this->keepWithNext) {
                $style['keepNext'] = true;
            }
            if ($list !== null) {
                $style['numStyle'] = $list;
                $style['numLevel'] = max(0, min(2, $this->listDepth - 1));
            }
            $run = $target->addTextRun($style);
            foreach ($node['content'] ?? [] as $text) {
                if ($text['type'] === 'hardBreak') {
                    $run->addTextBreak();

                    continue;
                }
                $font = [];
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
        $isList = in_array($node['type'], ['bulletList', 'orderedList'], true);
        $this->listDepth += $isList ? 1 : 0;
        foreach ($node['content'] ?? [] as $child) {
            $this->append($target, $child, $width, $list, $alignmentOverride, $insideTable);
        }
        $this->listDepth -= $isList ? 1 : 0;
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
        $outerKeepWithNext = $this->keepWithNext;
        $leadingHeader = true;
        $leadingHeading = true;
        $table = $target->addTable(['borderSize' => 4, 'borderColor' => $this->tableBorder, 'cellMargin' => 50, 'width' => 5000, 'unit' => TblWidth::PERCENT]);
        $merged = [];
        foreach ($rows as $rowIndex => $source) {
            $leadingHeader = $leadingHeader && array_reduce(
                $source['content'],
                fn (bool $carry, array $cell): bool => $carry && $cell['type'] === 'tableHeader',
                true,
            );
            $leadingHeading = $leadingHeading && ($source['content'][0]['type'] ?? null) === 'tableHeader';
            $row = $table->addRow(null, [...($leadingHeading ? ['cantSplit' => true] : []), ...($leadingHeader ? ['tblHeader' => true] : [])]);
            $cells = $source['content'];
            $column = 0;
            while ($column < $count) {
                if (isset($merged[$column])) {
                    $merge = $merged[$column];
                    $continued = $row->addCell(
                        (int) array_sum(array_slice($widths, $column, $merge['span'])),
                        $merge['options'],
                    );
                    if ($leadingHeading) {
                        $continued->addTextRun(['spaceAfter' => 0, 'keepNext' => true]);
                    }
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
                $destination = $row->addCell($cellWidth, $options);
                $this->keepWithNext = $leadingHeading;
                foreach ($cell['content'] as $child) {
                    $styled = $isHeader && $this->tableHeaderColor !== null && ($attrs['backgroundColor'] ?? null) === null
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
        $this->keepWithNext = $outerKeepWithNext;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, int|string>
     */
    private function cellBorderOptions(array $attrs): array
    {
        return match ($attrs['borderStyle'] ?? null) {
            'none' => ['borderSize' => 0, 'borderStyle' => 'none'],
            'thick' => ['borderSize' => 12, 'borderStyle' => 'single', 'borderColor' => $this->tableBorder],
            'thin' => ['borderSize' => 4, 'borderStyle' => 'single', 'borderColor' => $this->tableBorder],
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
