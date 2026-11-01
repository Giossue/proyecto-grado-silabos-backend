<?php

namespace App\Modules\Library\Domain\Contracts;

use App\Modules\Library\Domain\Data\CatalogBook;

interface BibliographicCatalog
{
    public const FIELDS = ['author', 'title', 'isbn', 'any'];

    /** @return list<CatalogBook> */
    public function search(string $field, string $term): array;
}
