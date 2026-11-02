<?php

namespace App\Modules\Library\Domain\Data;

final readonly class CatalogBook
{
    public const PHYSICAL = 'Libro físico';

    public function __construct(
        public string $id,
        public string $title,
        public string $author,
        public string $year,
        public string $place,
        public string $publisher,
        public string $isbn,
        public string $callNumber,
        public string $format = self::PHYSICAL,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'year' => $this->year,
            'place' => $this->place,
            'publisher' => $this->publisher,
            'isbn' => $this->isbn,
            'call_number' => $this->callNumber,
            'format' => $this->format,
        ];
    }
}
