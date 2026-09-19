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

    public function markdown(): string
    {
        return implode(
            "\n\n",
            array_map(
                static fn (AcademicSourceOcrPage $page): string => trim($page->markdown),
                $this->pages,
            ),
        );
    }

    /** @return list<array<string, mixed>> */
    public function pagesForStorage(): array
    {
        return array_map(
            static fn (AcademicSourceOcrPage $page): array => $page->toArray(),
            $this->pages,
        );
    }
}
