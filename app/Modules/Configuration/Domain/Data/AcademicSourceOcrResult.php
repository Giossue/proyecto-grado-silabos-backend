<?php

namespace App\Modules\Configuration\Domain\Data;

final readonly class AcademicSourceOcrResult
{
    /**
     * @param  list<AcademicSourceOcrPage>  $pages
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public string $model,
        public array $pages,
        public array $usage,
        public ?string $requestId,
    ) {}
}
