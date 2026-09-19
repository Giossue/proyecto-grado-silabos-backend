<?php

namespace App\Modules\Configuration\Domain\Data;

final readonly class AcademicSourceOcrPage
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public int $index,
        public string $markdown,
        public array $metadata,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'markdown' => $this->markdown,
            ...$this->metadata,
        ];
    }
}
