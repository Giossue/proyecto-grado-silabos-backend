<?php

namespace App\Modules\Configuration\Application;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class TransparentPng implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Suba un archivo PNG.');

            return;
        }

        $handle = fopen($value->getRealPath(), 'rb');
        $header = $handle === false ? false : fread($handle, 26);
        if ($handle !== false) {
            fclose($handle);
        }

        if (! is_string($header) || strlen($header) < 26 || ! str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
            $fail('El archivo debe ser un PNG.');

            return;
        }

        $colorType = ord($header[25]);
        if (! in_array($colorType, [4, 6], true)) {
            $fail('El logo debe ser un PNG sin fondo (con transparencia).');
        }
    }
}
