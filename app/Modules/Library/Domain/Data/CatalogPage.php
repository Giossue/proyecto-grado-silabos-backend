<?php

namespace App\Modules\Library\Domain\Data;

final readonly class CatalogPage
{
    /** @param  list<CatalogBook>  $books */
    public function __construct(
        public array $books,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    /** @return array{current_page: int, last_page: int, per_page: int, from: int|null, to: int|null, total: int} */
    public function meta(): array
    {
        $from = $this->books === [] ? null : ($this->page - 1) * $this->perPage + 1;

        return [
            'current_page' => $this->page,
            'last_page' => max(1, (int) ceil($this->total / $this->perPage)),
            'per_page' => $this->perPage,
            'from' => $from,
            'to' => $from === null ? null : $from + count($this->books) - 1,
            'total' => $this->total,
        ];
    }
}
