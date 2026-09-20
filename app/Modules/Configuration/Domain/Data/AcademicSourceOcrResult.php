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

    /**
     * @param  null|callable(string, array<string, mixed>): ?string  $imageUrlResolver
     */
    public function markdown(?callable $imageUrlResolver = null): string
    {
        return implode(
            "\n\n",
            array_map(
                static fn (AcademicSourceOcrPage $page): string => trim($page->renderMarkdown($imageUrlResolver)),
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
