<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Domain\Blocks\LayoutRules;
use Illuminate\Validation\ValidationException;

final class TemplateDocument
{
    public const FONTS = ['Arial', 'Calibri', 'Times New Roman', 'Verdana', 'Georgia'];

    public const CELL_ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public const CELL_BORDER_STYLES = ['thin', 'thick', 'none'];

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $variables
     * @param  array<string, array<string, mixed>>|null  $blocks
     * @return array<string, mixed>
     */
    public static function normalize(array $document, array $variables, ?LayoutRules $layout = null, ?array $blocks = null): array
    {
        if (strlen(json_encode($document, JSON_THROW_ON_ERROR)) > 2000000) {
            self::fail('El diseño pasa el límite de 2 MB. Divida el contenido en varias secciones.');
        }
        $count = 0;
        $visit = function (mixed $raw, string $parent, int $depth, bool $insideTable = false) use (&$visit, &$count, $variables, $layout, $blocks): array {
            if (++$count > 6000 || $depth > 12 || ! is_array($raw)) {
                self::fail('El diseño es demasiado grande o está mal formado.');
            }
            $type = $raw['type'] ?? '';
            $allowed = match ($parent) {
                '' => ['doc'],
                'doc' => $layout === null ? ['paragraph', 'table', 'bulletList', 'orderedList', 'documentBlock'] : ['paragraph', 'table', 'bulletList', 'orderedList', 'group'],
                'group' => ['paragraph', 'table', 'bulletList', 'orderedList', 'group'],
                'tableCell', 'tableHeader' => ['paragraph', 'table', 'bulletList', 'orderedList'],
                'paragraph' => $layout === null ? ['text', 'hardBreak', 'variable', ResponseKind::NODE] : ['text', 'hardBreak', 'variable', 'slot'],
                'table' => ['tableRow'],
                'tableRow' => ['tableCell', 'tableHeader'],
                'bulletList', 'orderedList' => ['listItem'],
                'listItem' => ['paragraph', 'bulletList', 'orderedList'],
                default => [],
            };
            if (! in_array($type, $allowed, true)) {
                self::fail('El diseño contiene un elemento no permitido.');
            }
            $node = ['type' => $type];
            $attrs = is_array($raw['attrs'] ?? null) ? $raw['attrs'] : [];
            if ($type === 'text') {
                if (! is_string($raw['text'] ?? null) || mb_strlen($raw['text']) > 20000 || $raw['text'] === '') {
                    self::fail('El texto del diseño no es válido.');
                }
                $node['text'] = $raw['text'];
            }
            if ($type === 'documentBlock') {
                $node['attrs'] = DocumentBlock::normalize($attrs, $blocks);
            }
            if ($type === ResponseKind::NODE) {
                $node['attrs'] = ResponseKind::attrs($attrs);
            }
            if ($type === 'slot') {
                $node['attrs'] = $layout?->slot($attrs) ?? [];
            }
            if ($type === 'variable') {
                $key = $attrs['id'] ?? null;
                if (! is_string($key) || ! in_array($key, $variables, true)) {
                    self::fail('Seleccione una variable del catálogo.');
                }
                $node['attrs'] = ['id' => $key, 'label' => $key];
                $listStyle = $attrs['listStyle'] ?? null;
                if ($listStyle !== null) {
                    if (! in_array($listStyle, ['bullet', 'number'], true)) {
                        self::fail('El formato de lista del dato no es válido.');
                    }
                    $node['attrs']['listStyle'] = $listStyle;
                }
            }
            if ($type === 'paragraph') {
                $align = $attrs['textAlign'] ?? 'left';
                if (! in_array($align, [null, 'left', 'center', 'right', 'justify'], true)) {
                    self::fail('Alineación no permitida.');
                }
                $node['attrs'] = ['textAlign' => $align];
            }
            if ($type === 'table' && ($attrs['repeatKey'] ?? null) !== null) {
                self::fail('La tabla usa filas que agrega el docente; use Texto libre para eso.');
            }
            if ($type === 'tableRow' && ! in_array($attrs['rowRole'] ?? 'fixed', [null, 'fixed'], true)) {
                self::fail('La tabla usa filas que agrega el docente; use Texto libre para eso.');
            }
            $insideTable = $insideTable || $type === 'table';
            if (in_array($type, ['tableCell', 'tableHeader'], true)) {
                $span = $attrs['colspan'] ?? 1;
                $rows = $attrs['rowspan'] ?? 1;
                if (! is_int($span) || $span < 1 || $span > 24 || ! is_int($rows) || $rows < 1 || $rows > 100) {
                    self::fail('Una combinación excede los límites de la tabla (24 columnas y 100 filas).');
                }
                $widths = $attrs['colwidth'] ?? null;
                if ($widths !== null && (! is_array($widths) || ! array_is_list($widths) || count($widths) !== $span)) {
                    self::fail('Anchos de columna no válidos.');
                }
                foreach ($widths ?? [] as $width) {
                    if (! is_int($width) || $width < 0 || $width > 1200) {
                        self::fail('Ancho de columna fuera del límite.');
                    }
                }
                $background = $attrs['backgroundColor'] ?? null;
                if ($background !== null && ! self::color($background)) {
                    self::fail('Color de celda no válido.');
                }
                $textColor = $attrs['textColor'] ?? null;
                if ($textColor !== null && ! self::color($textColor)) {
                    self::fail('Color de texto de celda no válido.');
                }
                $textAlign = $attrs['textAlign'] ?? null;
                if ($textAlign !== null && ! in_array($textAlign, self::CELL_ALIGNMENTS, true)) {
                    self::fail('Alineación de celda no permitida.');
                }
                $borderStyle = $attrs['borderStyle'] ?? null;
                if ($borderStyle !== null && ! in_array($borderStyle, self::CELL_BORDER_STYLES, true)) {
                    self::fail('Borde de celda no permitido.');
                }
                $bold = $attrs['bold'] ?? null;
                $italic = $attrs['italic'] ?? null;
                if (($bold !== null && ! is_bool($bold)) || ($italic !== null && ! is_bool($italic))) {
                    self::fail('Formato de celda no válido.');
                }
                $node['attrs'] = [
                    'colspan' => $span,
                    'rowspan' => $rows,
                    'colwidth' => $widths,
                    'backgroundColor' => $background,
                    'textColor' => $textColor,
                    'textAlign' => $textAlign,
                    'bold' => $bold,
                    'italic' => $italic,
                    'borderStyle' => $borderStyle,
                ];
            }
            if ($layout !== null && array_intersect(LayoutRules::DIRECTIVES, array_keys($attrs)) !== []) {
                $node['attrs'] = [...($node['attrs'] ?? []), ...$layout->directives($attrs, $type)];
            }
            if (isset($raw['marks'])) {
                if (! in_array($type, ['text', 'variable', 'slot', ResponseKind::NODE], true) || ! is_array($raw['marks']) || count($raw['marks']) > 4) {
                    self::fail('Formato de texto no válido.');
                }
                $marks = [];
                foreach ($raw['marks'] as $mark) {
                    $markType = is_array($mark) ? ($mark['type'] ?? '') : '';
                    if (! in_array($markType, ['bold', 'italic', 'underline', 'textStyle'], true)) {
                        self::fail('Formato de texto no permitido.');
                    }
                    $clean = ['type' => $markType];
                    if ($markType === 'textStyle') {
                        $style = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
                        $clean['attrs'] = [];
                        foreach (['fontFamily', 'fontSize', 'color'] as $property) {
                            if ($property === 'fontSize' || ($insideTable && $property === 'fontFamily')) {
                                continue;
                            }
                            $value = $style[$property] ?? null;
                            if ($value === null) {
                                continue;
                            }
                            $valid = match ($property) {
                                'fontFamily' => in_array($value, self::FONTS, true),
                                'color' => self::color($value),
                            };
                            if (! $valid) {
                                self::fail('Use las fuentes, tamaños y colores ofrecidos por el editor.');
                            }
                            $clean['attrs'][$property] = $value;
                        }
                    }
                    $marks[] = $clean;
                }
                $node['marks'] = $marks;
            }
            if (isset($raw['content'])) {
                if (! is_array($raw['content']) || ! array_is_list($raw['content'])) {
                    self::fail('Contenido del diseño no válido.');
                }
                $node['content'] = array_map(fn ($child) => $visit($child, $type, $depth + 1, $insideTable), $raw['content']);
            }
            if ($type === 'tableRow') {
                $node['content'] ??= [];
            }
            if (in_array($type, ['doc', 'table', 'tableCell', 'tableHeader', 'bulletList', 'orderedList', 'listItem', 'group'], true) && empty($node['content'])) {
                self::fail('El diseño tiene una estructura vacía.');
            }
            if ($type === 'table' && ($layout === null || ! self::repeatsCells($node))) {
                self::assertGrid($node);
            }

            return $node;
        };

        return $visit($document, '', 0);
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private static function repeatsCells(array $table): bool
    {
        foreach ($table['content'] as $row) {
            foreach ($row['content'] as $cell) {
                if (isset($cell['attrs']['repeat'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private static function assertGrid(array $table): void
    {
        $rows = $table['content'];
        if (count($rows) > 100) {
            self::fail('Una tabla admite hasta 100 filas de diseño.');
        }
        $occupied = [];
        $width = null;
        foreach ($rows as $r => $row) {
            $column = 0;
            foreach ($row['content'] as $cell) {
                while (isset($occupied[$r][$column])) {
                    $column++;
                }
                $span = $cell['attrs']['colspan'];
                $height = $cell['attrs']['rowspan'];
                if ($r + $height > count($rows) || $column + $span > 24) {
                    self::fail('Una celda combinada sale de la tabla.');
                }
                for ($y = $r; $y < $r + $height; $y++) {
                    for ($x = $column; $x < $column + $span; $x++) {
                        if (isset($occupied[$y][$x])) {
                            self::fail('Hay celdas combinadas superpuestas.');
                        }
                        $occupied[$y][$x] = true;
                    }
                }
                $column += $span;
            }
            $size = count($occupied[$r] ?? []);
            $width ??= $size;
            if ($size !== $width || $size === 0 || array_diff(range(0, $size - 1), array_keys($occupied[$r])) !== []) {
                self::fail('Las filas deben cubrir el mismo ancho. Separe la combinación incompleta.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    public static function nodes(array $document, string $type): array
    {
        $found = ($document['type'] ?? null) === $type ? [$document] : [];
        foreach ($document['content'] ?? [] as $child) {
            $found = [...$found, ...self::nodes($child, $type)];
        }

        return $found;
    }

    private static function color(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
