<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Estado compartido por las secciones del sílabo en Word: apariencia de la plantilla,
 * datos del snapshot y ancho útil de la hoja actual (cambia con la orientación).
 */
final class WordRenderContext
{
    public const BLUE = '4F81BD';

    public const LIGHT_BLUE = 'DBE5F1';

    /** Fila de totales del formato oficial. */
    public const TOTAL_BLUE = 'B8CCE4';

    public const HEADER_TEXT = '365F91';

    public const TABLE_BORDER = '000000';

    /** Ancho útil de la hoja en twips, según orientación y márgenes. */
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
        public readonly mixed $snapshotIdentification = null,
        public readonly array $templateVariables = [],
        public readonly array $academicContext = [],
    ) {}

    public function color(string $key): string
    {
        return ltrim((string) $this->appearance[$key], '#');
    }

    public function identificationHeaderBackground(): string
    {
        return $this->hasAppearance ? $this->color('table_header_background') : WordRenderContext::BLUE;
    }

    public function identificationHeaderColor(): string
    {
        return $this->hasAppearance ? $this->color('table_header_color') : 'FFFFFF';
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
