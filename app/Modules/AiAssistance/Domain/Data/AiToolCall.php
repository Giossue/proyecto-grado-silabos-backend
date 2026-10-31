<?php

namespace App\Modules\AiAssistance\Domain\Data;

final readonly class AiToolCall
{
    /** @param array<string, mixed>|null $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public ?array $arguments,
        public string $rawArguments,
    ) {}
}
