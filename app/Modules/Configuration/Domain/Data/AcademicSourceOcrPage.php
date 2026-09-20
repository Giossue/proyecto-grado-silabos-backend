<?php

namespace App\Modules\Configuration\Domain\Data;

final readonly class AcademicSourceOcrPage
{
    /**
     * @param  array<string, string>  $images
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $index,
        public string $markdown,
        public array $images = [],
        public ?array $structured = null,
        public array $metadata = [],
    ) {}
}
