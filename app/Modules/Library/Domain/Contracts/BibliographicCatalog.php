<?php

namespace App\Modules\Library\Domain\Contracts;

use App\Modules\Library\Domain\Data\CatalogPage;

interface BibliographicCatalog
{
    public const FIELDS = ['author', 'title', 'isbn', 'any'];

    public function search(string $field, string $term, int $page = 1): CatalogPage;
}
