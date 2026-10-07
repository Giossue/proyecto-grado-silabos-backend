<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FreeText;
use InvalidArgumentException;

/** Contenido institucional UEB de la plantilla oficial para los campos de tipo `fijo`. */
final class FixedContent
{
    /** @return array<string, mixed> */
    public static function official(string $key): array
    {
        return match ($key) {
            'indicadores' => FreeText::normalize(TemplateDocumentDefaults::evaluationIndicators()),
            'escala' => FreeText::normalize(TemplateDocumentDefaults::gradingScale()),
            'etica' => FreeText::fromText('Texto institucional de conducta y comportamiento ético pendiente: Administración debe escribirlo en la plantilla.'),
            'firmas' => self::signatures(),
            default => throw new InvalidArgumentException("No existe el contenido fijo «{$key}»."),
        };
    }

    /** @return array<string, mixed> Tabla de firmas con celdas en blanco: se firma sobre el documento impreso. */
    private static function signatures(): array
    {
        return FreeText::fixedTable(
            [['key' => 'nombre', 'label' => 'Nombre'], ['key' => 'cargo', 'label' => 'Cargo'], ['key' => 'firma', 'label' => 'Firma']],
            [['key' => 'elabora', 'label' => 'Elaborado por'], ['key' => 'revisa', 'label' => 'Revisado por'], ['key' => 'aprueba', 'label' => 'Aprobado por']],
            [],
        );
    }
}
