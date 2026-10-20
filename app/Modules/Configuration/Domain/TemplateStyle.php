<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Domain\Blocks\BlockDefinitionError;

final class TemplateStyle
{
    public const PAPERS = ['letter' => [12240, 15840], 'a4' => [11906, 16838], 'legal' => [12240, 20160]];

    public const FONTS = ['Arial', 'Calibri', 'Times New Roman', 'Verdana', 'Georgia'];

    public const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public const LOGOS = ['institution', 'faculty'];

    /**
     * @return array<string, mixed>
     */
    public static function define(mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            BlockDefinitionError::fail('«document» debe ser un objeto.');
        }
        $known = ['paper', 'orientation', 'margin_cm', 'font_family', 'line_height', 'paragraph_spacing_pt', 'sizes', 'colors', 'title', 'section_title', 'body', 'header', 'footer', 'metadata', 'empty_text', 'choices'];
        $unknown = array_diff(array_keys($raw), $known);
        if ($unknown !== []) {
            BlockDefinitionError::fail('Clave desconocida en «document»: «'.implode('», «', $unknown).'».');
        }
        $sizes = self::object($raw['sizes'] ?? [], 'document.sizes', ['title', 'section', 'body']);
        $colors = self::object($raw['colors'] ?? [], 'document.colors', ['text', 'title', 'table_header_background', 'table_header_text', 'table_border', 'empty_text']);
        $title = self::object($raw['title'] ?? [], 'document.title', ['text', 'bold', 'italic', 'alignment']);
        $section = self::object($raw['section_title'] ?? [], 'document.section_title', ['bold', 'italic', 'alignment']);
        $body = self::object($raw['body'] ?? [], 'document.body', ['alignment']);
        $choices = self::object($raw['choices'] ?? [], 'document.choices', ['margin_cm', 'title', 'section', 'body']);

        return [
            'paper' => self::among($raw['paper'] ?? 'letter', array_keys(self::PAPERS), 'document.paper'),
            'orientation' => self::among($raw['orientation'] ?? 'portrait', ['portrait', 'landscape'], 'document.orientation'),
            'margin_cm' => self::number($raw['margin_cm'] ?? 2.0, 'document.margin_cm', 0.5, 5),
            'font_family' => self::among($raw['font_family'] ?? 'Arial', self::FONTS, 'document.font_family'),
            'line_height' => self::number($raw['line_height'] ?? 1.15, 'document.line_height', 1, 3),
            'paragraph_spacing_pt' => self::number($raw['paragraph_spacing_pt'] ?? 6, 'document.paragraph_spacing_pt', 0, 36),
            'sizes' => [
                'title' => self::size($sizes['title'] ?? 14, 'document.sizes.title'),
                'section' => self::size($sizes['section'] ?? 11, 'document.sizes.section'),
                'body' => self::size($sizes['body'] ?? 11, 'document.sizes.body'),
            ],
            'colors' => [
                'text' => self::color($colors['text'] ?? '#000000', 'document.colors.text'),
                'title' => self::color($colors['title'] ?? '#000000', 'document.colors.title'),
                'table_header_background' => self::color($colors['table_header_background'] ?? null, 'document.colors.table_header_background', true),
                'table_header_text' => self::color($colors['table_header_text'] ?? null, 'document.colors.table_header_text', true),
                'table_border' => self::color($colors['table_border'] ?? '#000000', 'document.colors.table_border'),
                'empty_text' => self::color($colors['empty_text'] ?? '#595959', 'document.colors.empty_text'),
            ],
            'title' => [
                'text' => self::text($title['text'] ?? '', 'document.title.text', 180, true),
                'bold' => self::boolean($title['bold'] ?? true, 'document.title.bold'),
                'italic' => self::boolean($title['italic'] ?? false, 'document.title.italic'),
                'alignment' => self::among($title['alignment'] ?? 'center', self::ALIGNMENTS, 'document.title.alignment'),
            ],
            'section_title' => [
                'bold' => self::boolean($section['bold'] ?? true, 'document.section_title.bold'),
                'italic' => self::boolean($section['italic'] ?? false, 'document.section_title.italic'),
                'alignment' => self::among($section['alignment'] ?? 'left', self::ALIGNMENTS, 'document.section_title.alignment'),
            ],
            'body' => ['alignment' => self::among($body['alignment'] ?? 'left', self::ALIGNMENTS, 'document.body.alignment')],
            'header' => self::header($raw['header'] ?? null),
            'footer' => self::line($raw['footer'] ?? null, 'document.footer'),
            'metadata' => self::line($raw['metadata'] ?? null, 'document.metadata'),
            'empty_text' => self::text($raw['empty_text'] ?? '', 'document.empty_text', 120, true),
            'choices' => [
                'margin_cm' => self::list($choices['margin_cm'] ?? [], 'document.choices.margin_cm', fn (mixed $value, string $at): float => self::number($value, $at, 0.5, 5)),
                'title' => self::list($choices['title'] ?? [], 'document.choices.title', fn (mixed $value, string $at): int => self::size($value, $at)),
                'section' => self::list($choices['section'] ?? [], 'document.choices.section', fn (mixed $value, string $at): int => self::size($value, $at)),
                'body' => self::list($choices['body'] ?? [], 'document.choices.body', fn (mixed $value, string $at): int => self::size($value, $at)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function appearance(array $document): array
    {
        return [
            'font_family' => $document['font_family'],
            'body_font_size' => $document['sizes']['body'],
            'title_font_size' => $document['sizes']['title'],
            'section_font_size' => $document['sizes']['section'],
            'field_font_size' => $document['sizes']['body'],
            'margin_cm' => $document['margin_cm'],
            'orientation' => $document['orientation'],
            'text_color' => $document['colors']['text'],
            'accent_color' => $document['colors']['title'],
            'table_header_background' => $document['colors']['table_header_background'],
            'table_header_color' => $document['colors']['table_header_text'],
            'table_border' => $document['colors']['table_border'],
            'title_bold' => $document['title']['bold'],
            'title_italic' => $document['title']['italic'],
            'title_alignment' => $document['title']['alignment'],
            'section_bold' => $document['section_title']['bold'],
            'section_italic' => $document['section_title']['italic'],
            'section_alignment' => $document['section_title']['alignment'],
            'body_alignment' => $document['body']['alignment'],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{margin_cm: float, title_font_size: int, section_font_size: int, body_font_size: int}
     */
    public static function layout(array $document): array
    {
        return [
            'margin_cm' => (float) $document['margin_cm'],
            'title_font_size' => (int) $document['sizes']['title'],
            'section_font_size' => (int) $document['sizes']['section'],
            'body_font_size' => (int) $document['sizes']['body'],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public static function withLayout(array $document, array $layout): array
    {
        $choices = $document['choices'];
        $pick = function (string $key, string $choice, mixed $current) use ($layout, $choices): mixed {
            if (! array_key_exists($key, $layout)) {
                return $current;
            }
            $value = $layout[$key];
            $allowed = $choices[$choice];
            if (! is_numeric($value) || ($allowed !== [] && ! in_array((float) $value, array_map('floatval', $allowed), true))) {
                BlockDefinitionError::fail("layout.{$key}: elija uno de los valores ofrecidos.");
            }

            return $choice === 'margin_cm' ? (float) $value : (int) $value;
        };

        return self::define([
            ...$document,
            'margin_cm' => $pick('margin_cm', 'margin_cm', $document['margin_cm']),
            'sizes' => [
                'title' => $pick('title_font_size', 'title', $document['sizes']['title']),
                'section' => $pick('section_font_size', 'section', $document['sizes']['section']),
                'body' => $pick('body_font_size', 'body', $document['sizes']['body']),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $mapping
     * @return array<string, mixed>
     */
    public static function fromLegacyMapping(array $base, ?array $mapping): array
    {
        $layout = is_array($mapping['layout'] ?? null) ? $mapping['layout'] : [];
        $title = $mapping['title_block']['text'] ?? null;
        $document = [
            ...$base,
            'margin_cm' => is_numeric($layout['margin_cm'] ?? null) ? (float) $layout['margin_cm'] : $base['margin_cm'],
            'sizes' => [
                'title' => is_numeric($layout['title_font_size'] ?? null) ? (int) $layout['title_font_size'] : $base['sizes']['title'],
                'section' => is_numeric($layout['section_font_size'] ?? null) ? (int) $layout['section_font_size'] : $base['sizes']['section'],
                'body' => is_numeric($layout['body_font_size'] ?? null) ? (int) $layout['body_font_size'] : $base['sizes']['body'],
            ],
            'title' => [...$base['title'], 'text' => is_string($title) && trim($title) !== '' ? trim($title) : $base['title']['text']],
        ];
        if (! is_array($mapping['appearance'] ?? null)) {
            $document['colors'] = [...$base['colors'], 'table_header_background' => null, 'table_header_text' => null];
            $document['body'] = ['alignment' => 'left'];
        }

        return self::define($document);
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function object(mixed $raw, string $where, array $allowed): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            BlockDefinitionError::fail("«{$where}» debe ser un objeto.");
        }
        $unknown = array_diff(array_keys($raw), $allowed);
        if ($unknown !== []) {
            BlockDefinitionError::fail("Clave desconocida en «{$where}»: «".implode('», «', $unknown).'».');
        }

        return $raw;
    }

    /**
     * @return array{logos: list<array{source: string, height_pt: int}>, left_share: float}
     */
    private static function header(mixed $raw): array
    {
        $raw = self::object($raw ?? [], 'document.header', ['logos', 'left_share']);
        $logos = [];
        foreach (is_array($raw['logos'] ?? null) && array_is_list($raw['logos']) ? $raw['logos'] : [] as $index => $logo) {
            $at = "document.header.logos.{$index}";
            if (! is_array($logo) || ! in_array($logo['source'] ?? null, self::LOGOS, true)) {
                BlockDefinitionError::fail("{$at}.source: use «institution» o «faculty».");
            }
            $logos[] = ['source' => $logo['source'], 'height_pt' => self::size($logo['height_pt'] ?? 28, "{$at}.height_pt")];
        }
        if (count($logos) > 2) {
            BlockDefinitionError::fail('«document.header.logos» admite hasta dos logos: izquierda y derecha.');
        }

        return ['logos' => $logos, 'left_share' => self::number($raw['left_share'] ?? 0.5, 'document.header.left_share', 0.1, 0.9)];
    }

    /**
     * @return array{text: string, size: int, color: string}|null
     */
    private static function line(mixed $raw, string $where): ?array
    {
        if ($raw === null) {
            return null;
        }
        $raw = self::object($raw, $where, ['text', 'size', 'color']);

        return [
            'text' => self::text($raw['text'] ?? '', "{$where}.text", 300),
            'size' => self::size($raw['size'] ?? 8, "{$where}.size"),
            'color' => self::color($raw['color'] ?? '#7F7F7F', "{$where}.color"),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $raw, string $where, \Closure $item): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            BlockDefinitionError::fail("«{$where}» debe ser una lista.");
        }

        return array_map(fn (mixed $value, int $index): mixed => $item($value, "{$where}.{$index}"), $raw, array_keys($raw));
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function among(mixed $value, array $allowed, string $where): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            BlockDefinitionError::fail("«{$where}»: use ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    private static function number(mixed $value, string $where, float $min, float $max): float
    {
        if (! is_int($value) && ! is_float($value) || $value < $min || $value > $max) {
            BlockDefinitionError::fail("«{$where}»: debe ser un número entre {$min} y {$max}.");
        }

        return (float) $value;
    }

    private static function size(mixed $value, string $where): int
    {
        if (! is_int($value) || $value < 5 || $value > 72) {
            BlockDefinitionError::fail("«{$where}»: debe ser un tamaño entero entre 5 y 72.");
        }

        return $value;
    }

    private static function color(mixed $value, string $where, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
            BlockDefinitionError::fail("«{$where}»: debe ser un color #RRGGBB.");
        }

        return strtoupper($value);
    }

    private static function boolean(mixed $value, string $where): bool
    {
        if (! is_bool($value)) {
            BlockDefinitionError::fail("«{$where}»: debe ser true o false.");
        }

        return $value;
    }

    private static function text(mixed $value, string $where, int $max, bool $trim = false): string
    {
        $value ??= '';
        if (! is_string($value) || mb_strlen($value) > $max) {
            BlockDefinitionError::fail("«{$where}»: debe ser un texto de hasta {$max} caracteres.");
        }

        return $trim ? trim($value) : $value;
    }
}
