<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

final class WordRenderContext
{
    public const BLUE = '4F81BD';

    public const LIGHT_BLUE = 'DBE5F1';

    public const TOTAL_BLUE = 'B8CCE4';

    public const HEADER_TEXT = '365F91';

    public const TABLE_BORDER = '000000';

    public int $contentWidth = 9406;

    /**
     * @param  array<string, mixed>  $appearance
     * @param  bool  $hasAppearance  Los snapshots anteriores a la personalización conservan su formato local.
     * @param  array<string, string>  $templateVariables
     * @param  array<string, mixed>  $academicContext
     */
    public function __construct(
        public readonly array $appearance = [],
        public readonly bool $hasAppearance = false,
        public readonly array $templateVariables = [],
        public readonly array $academicContext = [],
    ) {}

    public const COMPACT_FONT_SIZE = 9;

    public const COMPACT_MARGIN_CM = 1.5;

    /** Copia para una sección compacta: letra de 9 pt y margen de 1.5 cm. */
    public function compact(int $pageWidth): self
    {
        $copy = new self(
            [...$this->appearance, 'body_font_size' => self::COMPACT_FONT_SIZE, 'field_font_size' => self::COMPACT_FONT_SIZE, 'margin_cm' => self::COMPACT_MARGIN_CM],
            $this->hasAppearance,
            $this->templateVariables,
            $this->academicContext,
        );
        $copy->contentWidth = $pageWidth - (2 * (int) round(Converter::cmToTwip(self::COMPACT_MARGIN_CM)));

        return $copy;
    }

    public function color(string $key): string
    {
        return ltrim((string) $this->appearance[$key], '#');
    }

    public function headerBackground(): string
    {
        return $this->hasAppearance ? $this->color('table_header_background') : WordRenderContext::LIGHT_BLUE;
    }

    public function headerColor(): string
    {
        return $this->hasAppearance ? $this->color('table_header_color') : WordRenderContext::HEADER_TEXT;
    }

    public function alignment(mixed $alignment): string
    {
        return match ($alignment) {
            'center' => Jc::CENTER,
            'right' => Jc::END,
            'justify' => Jc::BOTH,
            default => Jc::START,
        };
    }
}
