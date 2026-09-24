<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Corrige la rejilla (`w:tblGrid`) de cada tabla del DOCX.
 *
 * PhpWord arma la rejilla con los anchos de la fila que tiene más celdas, sin mirar
 * `gridSpan`: si esa fila combina columnas, la rejilla queda con menos columnas o con
 * anchos equivocados y LibreOffice (Gotenberg) dibuja el texto fuera de su celda. Aquí
 * la rejilla se recalcula con los bordes reales de todas las filas (suma acumulada de
 * `tcW` y `gridSpan`), las celdas se ajustan a esa rejilla y el diseño queda fijo para
 * que el texto se acomode dentro del ancho declarado.
 */
final class WordTableGrid
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function fix(string $xml): string
    {
        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return $xml;
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS);

        foreach (self::all($xpath, '//w:tbl', $dom) as $table) {
            self::table($xpath, $table);
        }

        return $dom->saveXML() ?: $xml;
    }

    /** @return list<int> anchos de la rejilla en twips */
    public static function columns(DOMXPath $xpath, DOMElement $table): array
    {
        /** @var array<int, int> $edges columna => posición de su borde derecho */
        $edges = [];
        foreach (self::rows($xpath, $table) as $cells) {
            $column = 0;
            $position = 0;
            foreach ($cells as $cell) {
                $column += $cell['span'];
                $position += $cell['width'];
                $edges[$column] ??= $position;
            }
        }
        if ($edges === []) {
            return [];
        }
        $count = max(array_keys($edges));
        $widths = [];
        $previous = 0;
        for ($column = 1; $column <= $count; $column++) {
            if (! isset($edges[$column])) {
                // Borde que ninguna fila marca: se reparte el tramo hasta el siguiente conocido.
                $next = $column + 1;
                while (! isset($edges[$next])) {
                    $next++;
                }
                $edges[$column] = $previous + intdiv($edges[$next] - $previous, $next - $column + 1);
            }
            $widths[] = max(1, $edges[$column] - $previous);
            $previous = max($previous, $edges[$column]);
        }

        return $widths;
    }

    private static function table(DOMXPath $xpath, DOMElement $table): void
    {
        $widths = self::columns($xpath, $table);
        if ($widths === []) {
            return;
        }
        $dom = $table->ownerDocument;
        if ($dom === null) {
            return;
        }

        $grid = self::first($xpath, 'w:tblGrid', $table);
        $newGrid = $dom->createElementNS(self::NS, 'w:tblGrid');
        foreach ($widths as $width) {
            $column = $dom->createElementNS(self::NS, 'w:gridCol');
            $column->setAttributeNS(self::NS, 'w:w', (string) $width);
            $column->setAttributeNS(self::NS, 'w:type', 'dxa');
            $newGrid->appendChild($column);
        }
        // El esquema pide `w:tblPr` antes de `w:tblGrid`; PhpWord los escribe al revés.
        $grid?->parentNode?->removeChild($grid);
        $properties = self::first($xpath, 'w:tblPr', $table);
        $table->insertBefore($newGrid, $properties !== null ? $properties->nextSibling : $table->firstChild);

        // Ancho total en twips y diseño fijo: LibreOffice respeta las columnas y parte las
        // líneas dentro de la celda en vez de ensancharla sobre la vecina.
        if ($properties instanceof DOMElement) {
            $tableWidth = self::first($xpath, 'w:tblW', $properties);
            if ($tableWidth instanceof DOMElement) {
                $tableWidth->setAttributeNS(self::NS, 'w:w', (string) array_sum($widths));
                $tableWidth->setAttributeNS(self::NS, 'w:type', 'dxa');
            }
            $layout = self::first($xpath, 'w:tblLayout', $properties);
            if ($layout instanceof DOMElement) {
                $layout->setAttributeNS(self::NS, 'w:type', 'fixed');
            }
        }

        // Cada celda mide exactamente lo que ocupan sus columnas.
        foreach (self::rows($xpath, $table) as $cells) {
            $column = 0;
            foreach ($cells as $cell) {
                $width = (int) array_sum(array_slice($widths, $column, $cell['span']));
                $cell['node']?->setAttributeNS(self::NS, 'w:w', (string) $width);
                $cell['node']?->setAttributeNS(self::NS, 'w:type', 'dxa');
                $column += $cell['span'];
            }
        }
    }

    /** @return list<list<array{span: int, width: int, node: DOMElement|null}>> */
    private static function rows(DOMXPath $xpath, DOMElement $table): array
    {
        $rows = [];
        foreach (self::all($xpath, 'w:tr', $table) as $row) {
            $cells = [];
            foreach (self::all($xpath, 'w:tc', $row) as $cell) {
                $width = self::first($xpath, 'w:tcPr/w:tcW', $cell);
                $span = self::first($xpath, 'w:tcPr/w:gridSpan', $cell);
                $cells[] = [
                    'span' => $span instanceof DOMElement ? max(1, (int) $span->getAttributeNS(self::NS, 'val')) : 1,
                    'width' => $width instanceof DOMElement ? (int) $width->getAttributeNS(self::NS, 'w') : 0,
                    'node' => $width instanceof DOMElement ? $width : null,
                ];
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /** @return list<DOMElement> */
    private static function all(DOMXPath $xpath, string $expression, \DOMNode $context): array
    {
        $nodes = $xpath->query($expression, $context);
        $elements = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function first(DOMXPath $xpath, string $expression, \DOMNode $context): ?DOMElement
    {
        return self::all($xpath, $expression, $context)[0] ?? null;
    }
}
