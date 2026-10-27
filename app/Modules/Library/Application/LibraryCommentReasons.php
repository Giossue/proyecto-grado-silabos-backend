<?php

namespace App\Modules\Library\Application;

final class LibraryCommentReasons
{
    /** @var array<string, string> */
    public const REASONS = [
        'libro_no_disponible' => 'El libro no está disponible en biblioteca',
        'codigo_incorrecto' => 'El código del libro es incorrecto',
        'isbn_incorrecto' => 'El ISBN es incorrecto',
        'edicion_desactualizada' => 'La edición está desactualizada',
        'datos_incompletos' => 'Los datos de la obra están incompletos',
        self::OTHER => 'Otro motivo',
    ];

    public const OTHER = 'otro';

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (string $code, string $label): array => ['code' => $code, 'label' => $label],
            array_keys(self::REASONS),
            array_values(self::REASONS),
        );
    }

    /** @param  list<string>  $codes */
    public static function text(array $codes, ?string $other = null): string
    {
        $parts = array_map(fn (string $code): string => self::REASONS[$code], array_values(array_diff($codes, [self::OTHER])));
        if (in_array(self::OTHER, $codes, true)) {
            $parts[] = rtrim(trim((string) $other), '. ');
        }

        return implode('; ', $parts).'.';
    }
}
