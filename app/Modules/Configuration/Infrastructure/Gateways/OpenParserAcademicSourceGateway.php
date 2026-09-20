<?php

namespace App\Modules\Configuration\Infrastructure\Gateways;

use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrPage;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrStatus;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrUnavailable;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class OpenParserAcademicSourceGateway implements AcademicSourceOcrGateway
{
    public function version(): string
    {
        return (string) config('openparser.adapter_version');
    }

    public function submit(StoredObject $pdf): string
    {
        $this->assertConfigured();
        $stream = Storage::disk($pdf->disco)->readStream($pdf->ruta_interna);
        if (! is_resource($stream)) {
            throw new AcademicSourceOcrUnavailable('No se pudo leer el PDF privado.');
        }

        try {
            $request = json_encode([
                'ocr_model' => (string) config('openparser.ocr_model'),
                'output_format' => (string) config('openparser.output_format'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => hash('sha256', $pdf->huella_sha256.':'.$this->version())])
                ->attach('request', $request, null, ['Content-Type' => 'application/json'])
                ->attach('file', $stream, $pdf->nombre_logico, ['Content-Type' => 'application/pdf'])
                ->post($this->url('/parse/async'));
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('OpenParser no está disponible.', previous: $exception);
        } catch (Throwable $exception) {
            throw new AcademicSourceOcrUnavailable('No fue posible enviar el PDF a OpenParser.', previous: $exception);
        } finally {
            fclose($stream);
        }

        $data = $this->responseObject($response, 'OpenParser rechazó el PDF.');
        $jobId = $data['id'] ?? null;
        if (! is_string($jobId) || trim($jobId) === '') {
            throw new AcademicSourceOcrContractException('OpenParser no devolvió el identificador del trabajo.');
        }
        if (($data['operation'] ?? null) !== 'parse' || ($data['output_format'] ?? null) !== 'openparser@1') {
            throw new AcademicSourceOcrContractException('OpenParser admitió un tipo de trabajo inesperado.');
        }

        return trim($jobId);
    }

    public function status(string $providerJobId): AcademicSourceOcrStatus
    {
        $this->assertConfigured();
        try {
            $response = $this->request()->get($this->url('/jobs/'.rawurlencode($providerJobId)));
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('No se pudo consultar el trabajo de OpenParser.', previous: $exception);
        }

        $data = $this->responseObject($response, 'OpenParser no pudo consultar el trabajo.');
        $state = $data['status'] ?? null;
        if (! is_string($state) || ! in_array($state, ['queued', 'running', 'succeeded', 'failed', 'indeterminate'], true)) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió un estado desconocido.');
        }
        $progress = is_array($data['progress'] ?? null) ? $data['progress'] : [];
        $total = max(0, (int) ($progress['total'] ?? 0));
        $completed = max(0, (int) ($progress['completed'] ?? 0));
        $percentage = $total > 0
            ? min(95, 20 + (int) floor((min($completed, $total) / $total) * 70))
            : ($state === 'running' ? 40 : 20);
        $mappedState = match ($state) {
            'succeeded' => 'done',
            'failed', 'indeterminate' => 'failed',
            default => $state === 'queued' ? 'pending' : 'running',
        };
        $failure = is_array($data['error'] ?? null) ? $data['error'] : [];

        return new AcademicSourceOcrStatus(
            state: $mappedState,
            progress: $mappedState === 'done' ? 95 : $percentage,
            result: $mappedState === 'done' ? ['available' => true] : null,
            error: is_string($failure['message'] ?? null) ? mb_substr($failure['message'], 0, 500) : null,
        );
    }

    public function result(string $providerJobId, array $result): AcademicSourceOcrResult
    {
        try {
            $response = $this->request()->get(
                $this->url('/jobs/'.rawurlencode($providerJobId).'/result'),
                ['format' => 'openparser@1'],
            );
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('No se pudo descargar el resultado de OpenParser.', previous: $exception);
        }
        if (strlen($response->body()) > (int) config('openparser.max_response_bytes')) {
            throw new AcademicSourceOcrContractException('El resultado de OpenParser excede el tamaño permitido.');
        }
        $document = $this->responseObject($response, 'OpenParser no pudo entregar el resultado.');

        return $this->toResult($providerJobId, $document);
    }

    /** @param array<string, mixed> $document */
    private function toResult(string $providerJobId, array $document): AcademicSourceOcrResult
    {
        if (($document['output_format'] ?? null) !== 'openparser@1') {
            throw new AcademicSourceOcrContractException('OpenParser devolvió un formato no compatible.');
        }
        $pages = $document['pages'] ?? null;
        $elements = $document['elements'] ?? null;
        $assets = $document['assets'] ?? [];
        if (! is_array($pages) || ! array_is_list($pages) || $pages === [] || ! is_array($elements) || ! array_is_list($elements) || ! is_array($assets) || ! array_is_list($assets)) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió un documento incompleto.');
        }

        $elementsById = [];
        foreach ($elements as $element) {
            if (is_array($element) && is_string($element['id'] ?? null)) {
                $elementsById[$element['id']] = $element;
            }
        }
        $assetsById = [];
        foreach ($assets as $asset) {
            if (is_array($asset) && is_string($asset['id'] ?? null)) {
                $assetsById[$asset['id']] = $asset;
            }
        }

        $ocrPages = [];
        foreach ($pages as $pageIndex => $page) {
            if (! is_array($page)) {
                throw new AcademicSourceOcrContractException('OpenParser devolvió una página inválida.');
            }
            $pageNumber = (int) ($page['number'] ?? 0);
            $width = (float) ($page['width'] ?? 0);
            $height = (float) ($page['height'] ?? 0);
            if ($pageNumber < 1 || $width <= 0 || $height <= 0) {
                throw new AcademicSourceOcrContractException('OpenParser devolvió dimensiones de página inválidas.');
            }
            $readingOrder = is_array($page['reading_order'] ?? null) ? $page['reading_order'] : [];
            $order = $readingOrder !== [] ? $readingOrder : ($page['element_ids'] ?? []);
            if (! is_array($order)) {
                $order = [];
            }
            $markdown = [];
            $images = [];
            $crops = [];
            foreach ($order as $elementId) {
                if (! is_string($elementId) || ! is_array($elementsById[$elementId] ?? null)) {
                    continue;
                }
                $element = $elementsById[$elementId];
                if (($element['kind'] ?? null) === 'figure') {
                    $image = $this->figure($element, $assetsById, $pageNumber, $width, $height);
                    if ($image !== null) {
                        $images[$image['id']] = $image['resource'];
                        if ($image['crop'] !== null) {
                            $crops[$image['id']] = $image['crop'];
                        }
                        $markdown[] = '!['.$this->markdownText($image['alt']).']('.$image['id'].')';
                    }

                    continue;
                }
                $rendered = $this->renderElement($element);
                if ($rendered !== '') {
                    $markdown[] = $rendered;
                }
            }
            $ocrPages[] = new AcademicSourceOcrPage(
                index: $pageIndex,
                markdown: implode("\n\n", $markdown),
                images: $images,
                structured: $page,
                metadata: ['page_number' => $pageNumber, 'figure_crops' => $crops],
            );
        }

        $provenance = is_array($document['provenance'] ?? null) ? $document['provenance'] : [];
        $model = is_string($provenance['model'] ?? null) ? $provenance['model'] : (string) config('openparser.ocr_model');

        return new AcademicSourceOcrResult(
            model: $model,
            pages: $ocrPages,
            usage: ['provider' => 'openparser', 'document_id' => $document['document_id'] ?? null],
            requestId: $providerJobId,
        );
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, array<string, mixed>>  $assets
     * @return array{id: string, resource: string, alt: string, crop: array<string, mixed>|null}|null
     */
    private function figure(array $element, array $assets, int $pageNumber, float $pageWidth, float $pageHeight): ?array
    {
        $elementId = is_string($element['id'] ?? null) ? $element['id'] : '';
        $alt = is_string($element['alt_text'] ?? null)
            ? $element['alt_text']
            : (is_string($element['caption'] ?? null) ? $element['caption'] : 'Figura');
        $assetId = is_string($element['asset_id'] ?? null) ? $element['asset_id'] : null;
        if ($assetId !== null && is_array($assets[$assetId] ?? null)) {
            $resource = $this->assetResource($assets[$assetId]);
            if ($resource !== null) {
                return ['id' => $this->imageId($assetId), 'resource' => $resource, 'alt' => $alt, 'crop' => null];
            }
        }

        $locations = is_array($element['locations'] ?? null) ? $element['locations'] : [];
        foreach ($locations as $location) {
            if (! is_array($location) || (int) ($location['page_number'] ?? 0) !== $pageNumber || ! is_array($location['bbox'] ?? null)) {
                continue;
            }
            $bbox = $location['bbox'];
            $left = (float) ($bbox['left'] ?? -1);
            $top = (float) ($bbox['top'] ?? -1);
            $right = (float) ($bbox['right'] ?? -1);
            $bottom = (float) ($bbox['bottom'] ?? -1);
            if ($left < 0 || $top < 0 || $right <= $left || $bottom <= $top || $right > $pageWidth || $bottom > $pageHeight) {
                continue;
            }
            $id = $this->imageId($elementId !== '' ? $elementId : "page-{$pageNumber}-figure");

            return [
                'id' => $id,
                'resource' => 'openparser-crop://'.$id,
                'alt' => $alt,
                'crop' => [
                    'page_number' => $pageNumber,
                    'page_width' => $pageWidth,
                    'page_height' => $pageHeight,
                    'bbox' => compact('left', 'top', 'right', 'bottom'),
                ],
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $asset */
    private function assetResource(array $asset): ?string
    {
        $base64 = $asset['data_base64'] ?? null;
        $mime = $asset['mime_type'] ?? null;
        if (is_string($base64) && is_string($mime) && str_starts_with($mime, 'image/')) {
            return 'data:'.strtolower($mime).';base64,'.$base64;
        }
        $uri = $asset['uri'] ?? null;

        return is_string($uri) && $this->isAllowedAssetUrl($uri) ? $uri : null;
    }

    /** @param array<string, mixed> $element */
    private function renderElement(array $element): string
    {
        $kind = $element['kind'] ?? null;
        if ($kind === 'text') {
            $text = is_string($element['text'] ?? null) ? trim($element['text']) : '';
            $role = $element['role'] ?? null;

            return match ($role) {
                'document_title' => $text === '' ? '' : '# '.$text,
                'heading' => $text === '' ? '' : '## '.$text,
                'list', 'list_item' => $text === '' ? '' : '- '.$text,
                default => $text,
            };
        }
        if ($kind === 'table') {
            return $this->tableHtml($element);
        }
        if ($kind === 'section') {
            $title = is_string($element['title'] ?? null) ? trim($element['title']) : '';

            return $title === '' ? '' : '## '.$title;
        }
        if ($kind === 'formula') {
            return is_string($element['value'] ?? null) ? trim($element['value']) : '';
        }
        if ($kind === 'key_value') {
            $key = is_array($element['key'] ?? null) && is_string($element['key']['text'] ?? null) ? trim($element['key']['text']) : '';
            $value = is_array($element['value'] ?? null) && is_string($element['value']['text'] ?? null) ? trim($element['value']['text']) : '';

            return trim($key.($key !== '' && $value !== '' ? ': ' : '').$value);
        }
        foreach (['text', 'value', 'caption', 'alt_text'] as $field) {
            if (is_string($element[$field] ?? null) && trim($element[$field]) !== '') {
                return trim($element[$field]);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $table */
    private function tableHtml(array $table): string
    {
        if (is_string($table['html'] ?? null) && preg_match('/<table\b/i', $table['html']) === 1) {
            return trim($table['html']);
        }
        $cells = is_array($table['cells'] ?? null) ? $table['cells'] : [];
        $rowCount = max(0, (int) ($table['row_count'] ?? 0));
        if ($cells === [] || $rowCount === 0) {
            return '';
        }
        $rows = array_fill(0, $rowCount, []);
        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }
            $row = (int) ($cell['row_index'] ?? -1);
            if ($row < 0 || $row >= $rowCount) {
                continue;
            }
            $rows[$row][] = $cell;
        }
        $html = '<table>';
        foreach ($rows as $cellsInRow) {
            usort($cellsInRow, static fn (array $a, array $b): int => ((int) ($a['column_index'] ?? 0)) <=> ((int) ($b['column_index'] ?? 0)));
            $html .= '<tr>';
            foreach ($cellsInRow as $cell) {
                $tag = ($cell['role'] ?? 'body') === 'body' ? 'td' : 'th';
                $rowspan = max(1, (int) ($cell['row_span'] ?? 1));
                $colspan = max(1, (int) ($cell['column_span'] ?? 1));
                $text = is_string($cell['text'] ?? null) ? $cell['text'] : '';
                $html .= "<{$tag} rowspan=\"{$rowspan}\" colspan=\"{$colspan}\">".htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')."</{$tag}>";
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    private function imageId(string $nativeId): string
    {
        return 'figure-'.substr(hash('sha256', $nativeId), 0, 24).'.png';
    }

    private function markdownText(string $value): string
    {
        return str_replace(["\r", "\n", '[', ']'], [' ', ' ', '(', ')'], trim($value));
    }

    private function assertConfigured(): void
    {
        if (config('openparser.enabled') !== true) {
            throw new AcademicSourceOcrUnavailable('La ingesta con OpenParser está desactivada.');
        }
        $key = trim((string) config('openparser.api_key'));
        if ($key === '' || preg_match('/[\r\n]/', $key) === 1) {
            throw new AcademicSourceOcrUnavailable('Falta configurar OPENPARSER_API_KEY.');
        }
        if (! $this->isSafeHttpsUrl((string) config('openparser.base_url'))) {
            throw new AcademicSourceOcrUnavailable('OPENPARSER_BASE_URL no es una URL HTTPS válida.');
        }
        if ((string) config('openparser.ocr_model') !== 'paddleocr-vl-1.6') {
            throw new AcademicSourceOcrUnavailable('La ingesta requiere el modelo paddleocr-vl-1.6.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('openparser.api_key'))
            ->withoutRedirecting()
            ->connectTimeout((int) config('openparser.connect_timeout_seconds'))
            ->timeout((int) config('openparser.timeout_seconds'));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('openparser.base_url'), '/').$path;
    }

    /** @return array<string, mixed> */
    private function responseObject(Response $response, string $message): array
    {
        if (in_array($response->status(), [408, 409, 429], true) || $response->serverError()) {
            throw new AcademicSourceOcrUnavailable($message);
        }
        if (! $response->successful()) {
            throw new AcademicSourceOcrContractException($message);
        }
        $payload = $response->json();
        if (! is_array($payload) || array_is_list($payload)) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió un objeto JSON inválido.');
        }

        return $payload;
    }

    private function isSafeHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'], $parts['pass'], $parts['fragment']);
    }

    private function isAllowedAssetUrl(string $url): bool
    {
        if (! $this->isSafeHttpsUrl($url)) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, config('openparser.allowed_asset_hosts', []), true);
    }
}
