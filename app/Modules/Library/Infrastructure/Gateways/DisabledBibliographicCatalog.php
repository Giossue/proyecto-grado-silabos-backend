<?php

namespace App\Modules\Library\Infrastructure\Gateways;

use App\Modules\Library\Domain\Contracts\BibliographicCatalog;
use App\Modules\Library\Domain\Data\CatalogPage;
use App\Modules\Library\Domain\Exceptions\CatalogUnavailable;

class DisabledBibliographicCatalog implements BibliographicCatalog
{
    public function search(string $field, string $term, int $page = 1): CatalogPage
    {
        throw new CatalogUnavailable('La búsqueda en el catálogo de la biblioteca está deshabilitada en este entorno.');
    }
}
