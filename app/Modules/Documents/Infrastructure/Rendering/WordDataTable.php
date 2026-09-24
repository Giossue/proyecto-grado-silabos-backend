<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Domain\TableLayout;
use App\Modules\Syllabus\Application\AcademicContextValues;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/** Tablas configurables (unidades, cabeceras combinadas, totales y resumen de planificación). */
final class WordDataTable
{
    public function __construct(private readonly WordRenderContext $context) {}

    /**
     * @param  array{columns: list<array{key: string, label: string, type: string, group: string|null, band: string|null, sum?: bool, width?: int|null}>, groups: list<array{key: string, label: string}>, bands: list<array{key: string, label: string}>, header_fields: list<array{key: string, label: string}>, totals: array{enabled: bool, label?: string}, repeat: array{enabled: bool, label?: string}}  $layout
     * @param  list<array<string, mixed>>  $rows
     */
    public function render(Section $section, array $layout, array $rows): void
    {
        $units = $this->units($layout, $rows);
        foreach ($units as $index => $unit) {
            if ($index > 0) {
                $section->addPageBreak();
            }
            $table = $section->addTable([
                'borderSize' => 4,
                'borderColor' => WordRenderContext::TABLE_BORDER,
                'cellMargin' => 60,
                'width' => 100 * 50,
                'unit' => TblWidth::PERCENT,
            ]);
            $widths = $this->widths($layout);
            $columnCount = count($layout['columns']);

            if ($layout['repeat']['enabled'] || $layout['header_fields'] !== []) {
                // Cabecera de unidad como en el formato oficial: «Unidad No.» y su número a la
                // izquierda (combinados hacia abajo), y a la derecha cada dato con su etiqueta.
                $header = $unit['header'];
                $labelStyle = ['bold' => true, 'size' => 9, 'color' => $this->context->headerColor()];
                $first = $widths[0];
                $labelSpan = max(1, min(4, $columnCount - 2));
                $labelWidth = $columnCount > 2 ? (int) array_sum(array_slice($widths, 1, $labelSpan)) : 0;
                $valueSpan = max(1, $columnCount - 1 - $labelSpan);
                $valueWidth = $this->context->contentWidth - $first - $labelWidth;
                $fields = $layout['header_fields'] !== [] ? $layout['header_fields'] : [['key' => '', 'label' => '']];
                foreach ($fields as $fieldIndex => $headerField) {
                    $row = $table->addRow();
                    $value = $headerField['key'] === '' ? '' : WordValues::cell($header, $headerField['key']);
                    if ($layout['repeat']['enabled']) {
                        $merge = $fieldIndex === 0 ? 'restart' : 'continue';
                        // «Unidad No.» y su número comparten la primera columna: dividirla en dos
                        // celdas añadiría una columna que las demás filas no tienen.
                        $unitCell = $row->addCell($first, [
                            'vMerge' => $merge,
                            'valign' => 'center',
                            ...($this->context->hasAppearance ? ['bgColor' => $this->context->headerBackground()] : []),
                        ]);
                        if ($fieldIndex === 0) {
                            $unitCell->addText(($layout['repeat']['label'] ?? 'Unidad').' No.', $labelStyle, ['spaceAfter' => 0, 'alignment' => Jc::CENTER]);
                            $unitCell->addText((string) ($index + 1), $labelStyle, ['spaceAfter' => 0, 'alignment' => Jc::CENTER]);
                        }
                        if ($columnCount > 2) {
                            $row->addCell($labelWidth, ['bgColor' => $this->context->headerBackground(), 'gridSpan' => $labelSpan])
                                ->addText($headerField['label'], $labelStyle, ['spaceAfter' => 0]);
                            $row->addCell($valueWidth, ['gridSpan' => $valueSpan])
                                ->addText($value, ['size' => 9], ['spaceAfter' => 0]);
                        } else {
                            $row->addCell($this->context->contentWidth - $first, ['gridSpan' => max(1, $columnCount - 1)])
                                ->addText($headerField['label'].($value === '' ? '' : ': '.$value), ['size' => 9], ['spaceAfter' => 0]);
                        }
                    } else {
                        $row->addCell($first, ['bgColor' => $this->context->headerBackground()])
                            ->addText($headerField['label'], $labelStyle, ['spaceAfter' => 0]);
                        $row->addCell($this->context->contentWidth - $first, ['gridSpan' => max(1, $columnCount - 1)])
                            ->addText($value, ['size' => 9], ['spaceAfter' => 0]);
                    }
                }
            }

            $this->headerRows($table, $layout, $widths);

            foreach ($unit['rows'] as $data) {
                $row = $table->addRow();
                foreach ($layout['columns'] as $columnIndex => $column) {
                    $cell = $row->addCell($widths[$columnIndex]);
                    $cell->addText(
                        WordValues::cell($data, $column['key']),
                        ['size' => 9],
                        ['spaceAfter' => 0, 'alignment' => $column['type'] === 'number' ? Jc::CENTER : Jc::START],
                    );
                }
            }
            if ($unit['rows'] === []) {
                $row = $table->addRow();
                $row->addCell($this->context->contentWidth, ['gridSpan' => $columnCount])
                    ->addText('Sin filas', ['italic' => true, 'size' => 9, 'color' => '595959'], ['spaceAfter' => 0]);
            }

            if ($layout['totals']['enabled']) {
                $row = $table->addRow();
                foreach ($layout['columns'] as $columnIndex => $column) {
                    $cell = $row->addCell($widths[$columnIndex], ['bgColor' => WordRenderContext::TOTAL_BLUE]);
                    $text = $columnIndex === 0
                        ? ($layout['totals']['label'] ?? 'Total')
                        : ($column['type'] === 'number' && ($column['sum'] ?? true) === true ? WordValues::sum($unit['rows'], $column['key']) : '');
                    $cell->addText(
                        $text,
                        ['bold' => true, 'size' => 9, 'color' => $this->context->hasAppearance ? $this->context->color('text_color') : WordRenderContext::HEADER_TEXT],
                        ['spaceAfter' => 0, 'alignment' => $columnIndex === 0 ? Jc::END : Jc::CENTER],
                    );
                }
            }
        }

        if (TableLayout::isPlanning($layout)) {
            $this->planningSummary($section, $layout, $rows);
        }
    }

    /**
     * Resumen derivado: muestra planificado/esperado y los créditos de la malla sin
     * convertir ninguno de esos resultados en una segunda fuente de verdad.
     *
     * @param  array<string, mixed>  $layout
     * @param  list<array<string, mixed>>  $rows
     */
    private function planningSummary(Section $section, array $layout, array $rows): void
    {
        $columns = TableLayout::columnsByRole($layout);
        $dataRows = array_values(array_filter(
            array_map(WordValues::rowData(...), $rows),
            fn (array $row): bool => ($row['_kind'] ?? null) !== 'unit',
        ));
        $weeks = array_values(array_unique(array_filter(array_map(
            fn (array $row): int => (int) ($row[$columns['week']] ?? 0),
            $dataRows,
        ))));
        sort($weeks);
        $actual = [
            'hours_acd' => WordValues::sum($dataRows, $columns['hours_acd']),
            'hours_ape' => WordValues::sum($dataRows, $columns['hours_ape']),
            'hours_aa' => WordValues::sum($dataRows, $columns['hours_aa']),
        ];
        $expected = [
            'hours_acd' => data_get($this->context->academicContext, 'subject.hours_ac'),
            'hours_ape' => data_get($this->context->academicContext, 'subject.hours_pae'),
            'hours_aa' => data_get($this->context->academicContext, 'subject.hours_aa'),
        ];

        $section->addText('Resumen general de planificación', ['bold' => true], ['spaceBefore' => 160, 'spaceAfter' => 60, 'keepNext' => true]);
        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => WordRenderContext::TABLE_BORDER,
            'cellMargin' => 50,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
        ]);
        $headers = ['Semanas', 'ACD', 'APE', 'AA', 'Créditos (malla)'];
        $header = $table->addRow(null, ['tblHeader' => true]);
        foreach ($headers as $label) {
            $header->addCell((int) floor($this->context->contentWidth / count($headers)), ['bgColor' => $this->context->headerBackground()])
                ->addText($label, ['bold' => true, 'size' => 8, 'color' => $this->context->headerColor()], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
        $values = [
            count($weeks).'/'.(string) (AcademicContextValues::scheduledSubject($this->context->academicContext, 'teaching_weeks') ?? '—'),
            $actual['hours_acd'].'/'.(string) ($expected['hours_acd'] ?? '—'),
            $actual['hours_ape'].'/'.(string) ($expected['hours_ape'] ?? '—'),
            $actual['hours_aa'].'/'.(string) ($expected['hours_aa'] ?? '—'),
            (string) (data_get($this->context->academicContext, 'subject.credits') ?? '—'),
        ];
        $valueRow = $table->addRow();
        foreach ($values as $value) {
            $valueRow->addCell((int) floor($this->context->contentWidth / count($values)))
                ->addText($value, ['size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
    }

    /**
     * Cabecera combinada: hasta tres filas (agrupamiento, grupo, columna). Cada
     * columna sabe en qué fila empieza su celda y cuántas ocupa; las vecinas del
     * mismo grupo se funden con `gridSpan`.
     *
     * @param  array{columns: list<array{key: string, label: string, type: string, group: string|null, band: string|null, sum?: bool, width?: int|null}>, groups: list<array{key: string, label: string}>, bands: list<array{key: string, label: string}>}  $layout
     * @param  list<int>  $widths
     */
    private function headerRows(Table $table, array $layout, array $widths): void
    {
        $columns = $layout['columns'];
        $hasBand = array_filter(array_column($columns, 'band')) !== [];
        $hasGroup = array_filter(array_column($columns, 'group')) !== [];
        $depth = 1 + ($hasBand ? 1 : 0) + ($hasGroup ? 1 : 0);
        $groupLabels = array_column($layout['groups'], 'label', 'key');
        $bandLabels = array_column($layout['bands'], 'label', 'key');

        /** @var list<list<array{id: string, label: string, span: int}|null>> $matrix fila => columna => celda */
        $matrix = array_fill(0, $depth, array_fill(0, count($columns), null));
        foreach ($columns as $index => $column) {
            $row = 0;
            if ($hasBand) {
                if ($column['band'] !== null) {
                    $matrix[0][$index] = ['id' => 'band:'.$column['band'], 'label' => $bandLabels[$column['band']] ?? '', 'span' => 1];
                    $row = 1;
                }
            }
            if ($hasGroup && $column['group'] !== null) {
                $span = ($hasBand && $column['band'] === null) ? 2 : 1;
                $matrix[$row][$index] = ['id' => 'group:'.$column['group'], 'label' => $groupLabels[$column['group']] ?? '', 'span' => $span];
                $row += $span;
            }
            $matrix[$row][$index] = ['id' => 'leaf:'.$column['key'], 'label' => $column['label'], 'span' => $depth - $row];
        }

        $style = ['bold' => true, 'size' => 8, 'color' => $this->context->headerColor()];
        $paragraph = ['spaceAfter' => 0, 'alignment' => Jc::CENTER];
        for ($rowIndex = 0; $rowIndex < $depth; $rowIndex++) {
            $row = $table->addRow(null, ['tblHeader' => true]);
            $columnIndex = 0;
            while ($columnIndex < count($columns)) {
                $cell = $matrix[$rowIndex][$columnIndex];
                if ($cell === null) {
                    // Continuación vertical de una celda que empezó arriba.
                    $row->addCell($widths[$columnIndex], ['bgColor' => $this->context->headerBackground(), 'vMerge' => 'continue']);
                    $columnIndex++;

                    continue;
                }
                $span = 1;
                $width = $widths[$columnIndex];
                while (
                    $columnIndex + $span < count($columns)
                    && ($matrix[$rowIndex][$columnIndex + $span]['id'] ?? null) === $cell['id']
                    && ! str_starts_with($cell['id'], 'leaf:')
                ) {
                    $width += $widths[$columnIndex + $span];
                    $span++;
                }
                $options = ['bgColor' => $this->context->headerBackground(), 'valign' => 'center'];
                if ($span > 1) {
                    $options['gridSpan'] = $span;
                }
                if ($cell['span'] > 1) {
                    $options['vMerge'] = 'restart';
                    for ($extra = 1; $extra < $cell['span']; $extra++) {
                        for ($offset = 0; $offset < $span; $offset++) {
                            $matrix[$rowIndex + $extra][$columnIndex + $offset] = null;
                        }
                    }
                }
                $row->addCell($width, $options)->addText($cell['label'], $style, $paragraph);
                $columnIndex += $span;
            }
        }
    }

    /**
     * Filas agrupadas por unidad; la fila con `_kind = unit` trae la cabecera.
     *
     * @param  array{repeat: array{enabled: bool, label?: string}}  $layout
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{header: array<string, mixed>, rows: list<array<string, mixed>>}>
     */
    private function units(array $layout, array $rows): array
    {
        $units = [];
        foreach ($rows as $row) {
            $data = WordValues::rowData($row);
            $unit = $layout['repeat']['enabled'] ? max(1, (int) ($data['_unit'] ?? 1)) : 1;
            $units[$unit] ??= ['header' => [], 'rows' => []];
            if (($data['_kind'] ?? null) === 'unit') {
                $units[$unit]['header'] = $data;

                continue;
            }
            $units[$unit]['rows'][] = $data;
        }
        ksort($units);

        return $units === [] ? [['header' => [], 'rows' => []]] : array_values($units);
    }

    /**
     * @param  array{columns: list<array{type: string, width?: int|null}>}  $layout
     * @return list<int>
     */
    private function widths(array $layout): array
    {
        // Con anchos declarados (formato oficial) se respetan sus proporciones; si no, las
        // columnas numéricas valen un tercio de las de texto.
        $declared = array_map(fn (array $column): ?int => $column['width'] ?? null, $layout['columns']);
        $weights = in_array(null, $declared, true) || $declared === []
            ? array_map(fn (array $column): int => $column['type'] === 'number' ? 1 : 3, $layout['columns'])
            : array_map('intval', $declared);
        $total = max(1, array_sum($weights));

        return array_map(fn (int $weight): int => (int) floor($this->context->contentWidth * $weight / $total), $weights);
    }
}
