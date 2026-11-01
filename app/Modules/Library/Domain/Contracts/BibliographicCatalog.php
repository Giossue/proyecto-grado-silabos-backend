<?php

namespace App\Modules\Library\Domain\Contracts;

use App\Modules\Library\Domain\Data\CatalogLibrary;
use App\Modules\Library\Domain\Data\CatalogPage;

interface BibliographicCatalog
{
    public const FIELDS = ['author', 'title', 'isbn', 'any'];

    /** @param  list<string>  $libraries  Códigos de las bibliotecas donde buscar; vacío es todas. */
    public function search(string $field, string $term, int $page = 1, array $libraries = []): CatalogPage;

    /** @return list<CatalogLibrary> */
    public function libraries(): array;
}
