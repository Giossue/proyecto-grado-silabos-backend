<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

final class WordRenderContext
{
    public const BLUE = '4F81BD';

    public const LIGHT_BLUE = 'C3D4EA';

    public const TOTAL_BLUE = 'B8CCE4';

    public const HEADER_TEXT = '365F91';

    /** Azul fino del recuadro de los campos en el formato oficial. */
    public const BOX_BORDER = '0070C0';

    public const TABLE_BORDER = '333333';

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

    /** Escritor de documentos con el aspecto congelado en la revisión. */
    public function documentWriter(): TemplateDocumentWord
    {
        return new TemplateDocumentWord(
            $this->hasAppearance ? (string) $this->appearance['body_alignment'] : null,
            $this->hasAppearance ? $this->color('table_header_background') : null,
            $this->hasAppearance ? $this->color('table_header_color') : null,
            (int) $this->appearance['body_font_size'],
        );
    }

    /**
     * Pinta un documento de texto enriquecido; sin contenido deja la marca «Sin contenido».
     *
     * @param  array<string, mixed>|null  $document
     */
    public function writeDocument(AbstractContainer $target, ?array $document, bool $emptyMark = true): void
    {
        if ($document === null) {
            if ($emptyMark) {
                $target->addText('Sin contenido', ['italic' => true, 'color' => '595959']);
            }

            return;
        }
        $this->documentWriter()->append($target, $document, $this->contentWidth);
    }

    /**
     * Pinta el contenido de un campo dentro de un recuadro de borde azul fino, como el formato oficial.
     *
     * @param  array<string, mixed>|null  $document
     */
    public function writeBoxed(AbstractContainer $target, ?array $document): void
    {
        $table = $target->addTable([
            'borderSize' => 6,
            'borderColor' => self::BOX_BORDER,
            'cellMargin' => 100,
            'width' => $this->contentWidth,
            'unit' => TblWidth::TWIP,
        ]);
        $cell = $table->addRow()->addCell($this->contentWidth);
        $this->writeDocument($cell, $document);
        $target->addTextBreak(0, null, ['spaceAfter' => 60]);
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
