<?php

namespace App\Modules\Documents\Domain\Data;

use Illuminate\Validation\ValidationException;

final readonly class DecisionSignature
{
    private const MAX_STATEMENT = 500;

    private const MAX_CMS = 200000;

    public function __construct(
        public string $statement,
        public string $cms,
    ) {}

    public static function from(mixed $input): ?self
    {
        if ($input === null) {
            return null;
        }
        $statement = is_array($input) ? ($input['statement'] ?? null) : null;
        $cms = is_array($input) ? ($input['cms'] ?? null) : null;
        if (! is_string($statement) || ! is_string($cms) || $statement === '' || $cms === ''
            || strlen($statement) > self::MAX_STATEMENT || strlen($cms) > self::MAX_CMS) {
            throw ValidationException::withMessages(['signature' => 'La firma no es válida.']);
        }

        return new self($statement, $cms);
    }
}
