<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Syllabus\Application\IdentificationCard;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/** Ficha de identificación de la asignatura en el sílabo en Word. */
final class WordIdentificationTable
{
    public function __construct(private readonly WordRenderContext $context) {}

    /**
     * Ficha de identificación calcada del formato: nueve columnas, combinaciones
     * horizontales con `gridSpan` y verticales con `vMerge`. Las celdas tapadas por
     * una combinación vertical se rellenan con continuaciones.
     *
     * @param  list<list<array{text: string, span: int, rows: int, style: string, bold: bool, small: bool, center: bool}>>  $grid
     */
    public function render(Section $section, array $grid): void
    {
        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => WordRenderContext::TABLE_BORDER,
            'cellMargin' => 50,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
        ]);
        $widths = array_map(
            fn (float $percent): int => (int) round($this->context->contentWidth * $percent / 100),
            IdentificationCard::WIDTHS,
        );
        /** @var array<int, array{rows: int, span: int}> $merged columna => filas pendientes de continuación */
        $merged = [];

        foreach ($grid as $cells) {
            $row = $table->addRow();
            $column = 0;
            $queue = $cells;
            while ($column < count($widths)) {
                if (isset($merged[$column])) {
                    $span = $merged[$column]['span'];
                    $row->addCell($this->width($widths, $column, $span), ['vMerge' => 'continue', 'gridSpan' => $span]);
                    $merged[$column]['rows']--;
                    if ($merged[$column]['rows'] <= 0) {
                        unset($merged[$column]);
                    }
                    $column += $span;

                    continue;
                }
                $cell = array_shift($queue);
                if ($cell === null) {
                    break;
                }
                $options = ['valign' => 'center'];
                if ($cell['span'] > 1) {
                    $options['gridSpan'] = $cell['span'];
                }
                if ($cell['rows'] > 1) {
                    $options['vMerge'] = 'restart';
                    $merged[$column] = ['rows' => $cell['rows'] - 1, 'span' => $cell['span']];
                }
                if ($cell['style'] === 'blue') {
                    $options['bgColor'] = $this->context->identificationHeaderBackground();
                } elseif ($cell['style'] === 'shade') {
                    $options['bgColor'] = WordRenderContext::LIGHT_BLUE;
                }
                $font = [
                    'bold' => $cell['bold'],
                    'size' => $cell['small'] ? 7 : 9,
                    'color' => $cell['style'] === 'blue'
                        ? $this->context->identificationHeaderColor()
                        : $this->context->color('text_color'),
                ];
                $target = $row->addCell($this->width($widths, $column, $cell['span']), $options);
                $lines = preg_split('/\R/u', $cell['text']) ?: [''];
                foreach ($lines as $index => $line) {
                    // «ETIQUETA:» seguida de texto del docente: la etiqueta va en negrita.
                    $lineFont = $index === 0 && count($lines) > 1 && str_ends_with(trim($line), ':')
                        ? ['bold' => true] + $font
                        : $font;
                    $target->addText($line, $lineFont, ['spaceAfter' => 0, 'alignment' => $cell['center'] ? Jc::CENTER : Jc::START]);
                }
                $column += $cell['span'];
            }
        }
    }

    /** @param list<int> $widths */
    private function width(array $widths, int $from, int $span): int
    {
        return (int) array_sum(array_slice($widths, $from, $span));
    }
}
