<?php

namespace App\Modules\Configuration\Domain\Data;

final readonly class AcademicSourceOcrStatus
{
    /** @param array<string, mixed>|null $result */
    public function __construct(
        public string $state,
        public int $progress,
        public ?array $result = null,
        public ?string $error = null,
    ) {}
}
