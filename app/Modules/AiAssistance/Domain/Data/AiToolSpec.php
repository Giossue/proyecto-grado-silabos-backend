<?php

namespace App\Modules\AiAssistance\Domain\Data;

final readonly class AiToolSpec
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
