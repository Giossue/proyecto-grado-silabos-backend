<?php

namespace App\Modules\Library\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Library\Domain\Contracts\BibliographicCatalog;
use App\Modules\Library\Domain\Data\CatalogBook;
use App\Modules\Library\Domain\Exceptions\CatalogUnavailable;
use App\Modules\Library\Presentation\Http\Requests\SearchBibliographicCatalogRequest;
use Illuminate\Http\JsonResponse;

class BibliographicCatalogController extends Controller
{
    public function search(SearchBibliographicCatalogRequest $request, BibliographicCatalog $catalog): JsonResponse
    {
        try {
            $page = $catalog->search($request->string('field')->toString(), $request->string('q')->toString(), max(1, $request->integer('page', 1)));
        } catch (CatalogUnavailable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'catalog_unavailable',
            ], 503);
        }

        return response()->json([
            'data' => array_map(fn (CatalogBook $book): array => $book->toArray(), $page->books),
            'meta' => $page->meta(),
        ]);
    }
}
