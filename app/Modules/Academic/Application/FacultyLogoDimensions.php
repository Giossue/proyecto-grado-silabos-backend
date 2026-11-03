<?php

namespace App\Modules\Academic\Application;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Limita el ancho y el alto del logo leyendo solo la cabecera de la imagen (getimagesize), antes de
 * que GD lo decodifique: un PNG pequeño puede declarar millones de píxeles y agotar la memoria.
 */
final class FacultyLogoDimensions implements ValidationRule
{
    public const MAX_PIXELS = 4000;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }
        $size = @getimagesize((string) $value->getRealPath());
        // Si no es una imagen legible, las otras reglas del logo ya lo informan.
        if ($size !== false && ($size[0] > self::MAX_PIXELS || $size[1] > self::MAX_PIXELS)) {
            $fail('El logo no puede superar '.self::MAX_PIXELS.' × '.self::MAX_PIXELS.' píxeles.');
        }
    }
}
