<?php

namespace App\Modules\Configuration\Domain\Blocks;

use Illuminate\Validation\ValidationException;

final class BlockDefinitionError
{
    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['definition' => $message]);
    }
}
