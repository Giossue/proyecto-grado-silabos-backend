<?php

namespace App\Modules\Library\Infrastructure\Gateways;

use App\Modules\Library\Domain\Contracts\BibliographicCatalog;
use App\Modules\Library\Domain\Data\CatalogBook;
use App\Modules\Library\Domain\Data\CatalogPage;
use App\Modules\Library\Domain\Exceptions\CatalogUnavailable;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta de solo lectura al catálogo público (OPAC) de Koha: busca en el RSS y lee cada
 * registro en MARCXML. No necesita usuario ni contraseña.
 */
class KohaPublicCatalog implements BibliographicCatalog
{
    private const MARC_NAMESPACE = 'http://www.loc.gov/MARC21/slim';

    private const OPENSEARCH_NAMESPACE = 'http://a9.com/-/spec/opensearch/1.1/';

    private const MAX_WORDS = 6;

    private const INDEXES = ['author' => 'au', 'title' => 'ti', 'isbn' => 'isbn', 'any' => 'kw'];

    public function search(string $field, string $term, int $page = 1): CatalogPage
    {
        $perPage = max(1, (int) config('catalog.koha.per_page'));
        $page = max(1, $page);
        $query = $this->query($field, $term);
        if ($query === null) {
            return new CatalogPage([], 0, $page, $perPage);
        }
        [$ids, $total] = $this->ids($query, $page, $perPage);

        return new CatalogPage($ids === [] ? [] : $this->books($ids), $total, $page, $perPage);
    }

    /**
     * Cada palabra se busca como prefijo («zootec» encuentra «zootecnia») y todas deben aparecer.
     * Solo se conservan letras y números, así el texto no puede traer sintaxis de búsqueda.
     */
    private function query(string $field, string $term): ?string
    {
        $index = self::INDEXES[$field] ?? null;
        if ($index === null) {
            return null;
        }
        if ($field === 'isbn') {
            $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $term));

            return in_array(strlen($isbn), [10, 13], true) ? "isbn:{$isbn}" : null;
        }
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $word): bool => ! in_array(mb_strtolower($word), ['and', 'or', 'not'], true),
        ));

        return $words === [] ? null : implode(' AND ', array_map(
            fn (string $word): string => "{$index},rtrn:{$word}",
            array_slice($words, 0, self::MAX_WORDS),
        ));
    }

    /** @return array{list<string>, int} */
    private function ids(string $query, int $page, int $perPage): array
    {
        $response = $this->get('/cgi-bin/koha/opac-search.pl', [
            'q' => $query,
            'format' => 'rss2',
            'count' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);
        $xml = $this->xml($response);
        $ids = [];
        foreach ($xml->getElementsByTagName('item') as $item) {
            $link = $item->getElementsByTagName('link')->item(0);
            if ($link !== null && preg_match('/biblionumber=(\d+)/', $link->textContent, $match) === 1) {
                $ids[$match[1]] = $match[1];
            }
        }
        $total = $xml->getElementsByTagNameNS(self::OPENSEARCH_NAMESPACE, 'totalResults')->item(0)?->textContent;

        return [array_slice(array_values($ids), 0, $perPage), max(is_numeric($total) ? (int) $total : 0, count($ids))];
    }

    /**
     * @param  list<string>  $ids
     * @return list<CatalogBook>
     */
    private function books(array $ids): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $id) => $this->configure($pool->as($id))->get($this->url('/cgi-bin/koha/opac-export.pl'), [
                    'op' => 'export', 'bib' => $id, 'format' => 'marcxml',
                ]),
                $ids,
            ), max(1, (int) config('catalog.koha.concurrent_requests')));
        } catch (Throwable $exception) {
            throw new CatalogUnavailable('No fue posible consultar el catálogo de la biblioteca.', previous: $exception);
        }
        $books = [];
        foreach ($ids as $id) {
            $response = $responses[$id] ?? null;
            if (! $response instanceof Response || ! $response->successful() || ! $this->withinLimit($response)) {
                continue;
            }
            $book = $this->book($id, $response->body());
            if ($book !== null) {
                $books[] = $book;
            }
        }

        return $books;
    }

    private function book(string $id, string $marcXml): ?CatalogBook
    {
        $document = $this->document($marcXml);
        if ($document === null) {
            return null;
        }
        $marc = $this->subfields($document);
        $first = fn (string $tag, string $code): string => $this->clean($marc["{$tag}{$code}"] ?? '');
        $title = $this->clean($first('245', 'a').' '.$first('245', 'b'));
        if ($title === '') {
            return null;
        }
        $author = $first('100', 'a') ?: $first('110', 'a') ?: $first('700', 'a');
        $publication = $first('264', 'c') ?: $first('260', 'c');
        $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', (string) strtok($first('020', 'a'), ' (')));
        $callNumber = $first('952', 'o') ?: $this->clean($first('082', 'a').' '.$first('082', 'b'));

        return new CatalogBook(
            id: $id,
            title: $title,
            author: $author,
            year: preg_match('/\d{4}/', $publication, $year) === 1 ? $year[0] : '',
            place: $first('264', 'a') ?: $first('260', 'a'),
            publisher: $first('264', 'b') ?: $first('260', 'b'),
            isbn: $isbn,
            callNumber: $callNumber,
        );
    }

    /**
     * Primer valor de cada subcampo MARC, con la clave «etiqueta + código» (por ejemplo `245a`).
     *
     * @return array<string, string>
     */
    private function subfields(DOMDocument $document): array
    {
        $values = [];
        foreach ($document->getElementsByTagNameNS(self::MARC_NAMESPACE, 'datafield') as $field) {
            foreach ($field->getElementsByTagNameNS(self::MARC_NAMESPACE, 'subfield') as $subfield) {
                $values[$field->getAttribute('tag').$subfield->getAttribute('code')] ??= $subfield->textContent;
            }
        }

        return $values;
    }

    private function clean(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return trim($value, " \t/:;,.");
    }

    /** @param  array<string, scalar>  $query */
    private function get(string $path, array $query): Response
    {
        try {
            $response = $this->configure(Http::accept('application/xml, text/xml'))->get($this->url($path), $query);
        } catch (ConnectionException $exception) {
            throw new CatalogUnavailable('El catálogo de la biblioteca no está disponible.', previous: $exception);
        } catch (Throwable $exception) {
            throw new CatalogUnavailable('No fue posible consultar el catálogo de la biblioteca.', previous: $exception);
        }
        if (! $response->successful()) {
            throw new CatalogUnavailable('El catálogo de la biblioteca respondió con un error.');
        }

        return $response;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->withUserAgent('Silabos-UEB/1.0')
            ->withoutRedirecting()
            ->connectTimeout((int) config('catalog.koha.connect_timeout_seconds'))
            ->timeout((int) config('catalog.koha.timeout_seconds'));
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('catalog.koha.base_url'), '/');
        if ($base === '') {
            throw new CatalogUnavailable('Falta configurar la dirección del catálogo de la biblioteca.');
        }

        return $base.$path;
    }

    private function xml(Response $response): DOMDocument
    {
        $document = $this->withinLimit($response) ? $this->document($response->body()) : null;
        if ($document === null) {
            throw new CatalogUnavailable('El catálogo de la biblioteca devolvió una respuesta que no se pudo leer.');
        }

        return $document;
    }

    private function withinLimit(Response $response): bool
    {
        return strlen($response->body()) <= (int) config('catalog.koha.response_bytes');
    }

    private function document(string $xml): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}
