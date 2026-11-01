<?php

namespace App\Modules\Library\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Library\Application\Queries\FacultyCatalogLibraries;
use App\Modules\Library\Domain\Contracts\BibliographicCatalog;
use App\Modules\Library\Domain\Data\CatalogBook;
use App\Modules\Library\Domain\Data\CatalogLibrary;
use App\Modules\Library\Domain\Exceptions\CatalogUnavailable;
use App\Modules\Library\Presentation\Http\Requests\SearchBibliographicCatalogRequest;
use App\Modules\Library\Presentation\Http\Requests\ViewCatalogLibrariesRequest;
use Illuminate\Http\JsonResponse;

class BibliographicCatalogController extends Controller
{
    public function search(SearchBibliographicCatalogRequest $request, BibliographicCatalog $catalog, FacultyCatalogLibraries $faculty): JsonResponse
    {
        try {
            $page = $catalog->search(
                $request->string('field')->toString(),
                $request->string('q')->toString(),
                max(1, $request->integer('page', 1)),
                $faculty->forRequest($request),
            );
        } catch (CatalogUnavailable $exception) {
            return $this->unavailable($exception);
        }

        return response()->json([
            'data' => array_map(fn (CatalogBook $book): array => $book->toArray(), $page->books),
            'meta' => $page->meta(),
        ]);
    }

    public function libraries(ViewCatalogLibrariesRequest $request, BibliographicCatalog $catalog): JsonResponse
    {
        try {
            $libraries = $catalog->libraries();
        } catch (CatalogUnavailable $exception) {
            return $this->unavailable($exception);
        }

        return response()->json(['data' => array_map(fn (CatalogLibrary $library): array => $library->toArray(), $libraries)]);
    }

    private function unavailable(CatalogUnavailable $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'code' => 'catalog_unavailable'], 503);
    }
}
