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

final class PaddleOcrAcademicSourceGateway implements AcademicSourceOcrGateway
{
    public function version(): string
    {
        return (string) config('paddleocr.adapter_version');
    }

    public function submit(StoredObject $pdf): string
    {
        $this->assertConfigured();
        $stream = Storage::disk($pdf->disco)->readStream($pdf->ruta_interna);
        if (! is_resource($stream)) {
            throw new AcademicSourceOcrUnavailable('No se pudo leer el PDF privado.');
        }

        try {
            $response = $this->request()
                ->attach('file', $stream, $pdf->nombre_logico)
                ->post($this->jobsUrl(), [
                    'model' => (string) config('paddleocr.model'),
                    'optionalPayload' => json_encode(config('paddleocr.options'), JSON_THROW_ON_ERROR),
                ]);
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('PaddleOCR no está disponible.', previous: $exception);
        } catch (Throwable $exception) {
            throw new AcademicSourceOcrUnavailable('No fue posible enviar el PDF a PaddleOCR.', previous: $exception);
        } finally {
            fclose($stream);
        }

        $data = $this->responseData($response, 'PaddleOCR rechazó el PDF.');
        $jobId = $data['jobId'] ?? null;
        if (! is_string($jobId) || trim($jobId) === '') {
            throw new AcademicSourceOcrContractException('PaddleOCR no devolvió el identificador del trabajo.');
        }

        return trim($jobId);
    }

    public function status(string $providerJobId): AcademicSourceOcrStatus
    {
        $this->assertConfigured();
        try {
            $response = $this->request()->get($this->jobsUrl().'/'.rawurlencode($providerJobId));
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('No se pudo consultar el trabajo de PaddleOCR.', previous: $exception);
        }

        $data = $this->responseData($response, 'PaddleOCR no pudo consultar el trabajo.');
        $state = $data['state'] ?? null;
        if (! is_string($state) || ! in_array($state, ['pending', 'running', 'done', 'failed'], true)) {
            throw new AcademicSourceOcrContractException('PaddleOCR devolvió un estado desconocido.');
        }
        $progress = $data['extractProgress'] ?? [];
        $total = is_array($progress) ? (int) ($progress['totalPages'] ?? 0) : 0;
        $extracted = is_array($progress) ? (int) ($progress['extractedPages'] ?? 0) : 0;
        $percentage = $total > 0 ? min(95, 20 + (int) floor(($extracted / $total) * 70)) : ($state === 'running' ? 30 : 20);

        return new AcademicSourceOcrStatus(
            state: $state,
            progress: $state === 'done' ? 95 : $percentage,
            result: is_array($data['resultUrl'] ?? null) ? $data['resultUrl'] : null,
            error: is_string($data['errorMsg'] ?? null) ? mb_substr($data['errorMsg'], 0, 500) : null,
        );
    }

    public function result(string $providerJobId, array $result): AcademicSourceOcrResult
    {
        $url = $result['jsonUrl'] ?? null;
        if (! is_string($url) || ! $this->isSafeHttpsUrl($url)) {
            throw new AcademicSourceOcrContractException('PaddleOCR no devolvió una URL de resultado válida.');
        }

        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout((int) config('paddleocr.connect_timeout_seconds'))
                ->timeout((int) config('paddleocr.timeout_seconds'))
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('No se pudo descargar el resultado de PaddleOCR.', previous: $exception);
        }
        if (! $response->successful()) {
            throw new AcademicSourceOcrUnavailable('La descarga del resultado de PaddleOCR falló.');
        }
        if (strlen($response->body()) > (int) config('paddleocr.max_response_bytes')) {
            throw new AcademicSourceOcrContractException('El resultado de PaddleOCR excede el tamaño permitido.');
        }

        $pages = [];
        $dataInfo = [];
        foreach (preg_split('/\R/u', trim($response->body())) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new AcademicSourceOcrContractException('PaddleOCR devolvió un JSONL inválido.', previous: $exception);
            }
            $entry = is_array($payload) ? ($payload['result'] ?? null) : null;
            if (! is_array($entry)) {
                throw new AcademicSourceOcrContractException('PaddleOCR devolvió una línea sin resultado.');
            }
            if (is_array($entry['dataInfo'] ?? null)) {
                $dataInfo = [...$dataInfo, ...$entry['dataInfo']];
            }
            $layoutPages = $entry['layoutParsingResults'] ?? null;
            if (! is_array($layoutPages)) {
                throw new AcademicSourceOcrContractException('PaddleOCR no devolvió páginas estructuradas.');
            }
            foreach ($layoutPages as $item) {
                if (! is_array($item) || ! is_array($item['markdown'] ?? null)) {
                    throw new AcademicSourceOcrContractException('PaddleOCR devolvió una página inválida.');
                }
                $markdown = $item['markdown'];
                $text = $markdown['text'] ?? null;
                if (! is_string($text)) {
                    throw new AcademicSourceOcrContractException('PaddleOCR devolvió una página sin texto.');
                }
                $images = array_filter(
                    is_array($markdown['images'] ?? null) ? $markdown['images'] : [],
                    static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
                    ARRAY_FILTER_USE_BOTH,
                );
                $pages[] = new AcademicSourceOcrPage(
                    index: count($pages),
                    markdown: trim($text),
                    images: $images,
                    structured: is_array($item['prunedResult'] ?? null) ? $item['prunedResult'] : null,
                    metadata: [
                        'input_image' => is_string($item['inputImage'] ?? null) ? $item['inputImage'] : null,
                        'output_image_ids' => is_array($item['outputImages'] ?? null) ? array_keys($item['outputImages']) : [],
                    ],
                );
            }
        }
        if ($pages === []) {
            throw new AcademicSourceOcrContractException('PaddleOCR no devolvió páginas.');
        }

        return new AcademicSourceOcrResult(
            model: (string) config('paddleocr.model'),
            pages: $pages,
            usage: ['data_info' => $dataInfo],
            requestId: $providerJobId,
        );
    }

    private function assertConfigured(): void
    {
        if (config('paddleocr.enabled') !== true) {
            throw new AcademicSourceOcrUnavailable('La ingesta con PaddleOCR está desactivada.');
        }
        $token = trim((string) config('paddleocr.access_token'));
        if ($token === '' || preg_match('/[\r\n]/', $token) === 1) {
            throw new AcademicSourceOcrUnavailable('Falta configurar PADDLEOCR_ACCESS_TOKEN.');
        }
        if (! $this->isSafeHttpsUrl((string) config('paddleocr.base_url'))) {
            throw new AcademicSourceOcrUnavailable('PADDLEOCR_BASE_URL no es una URL HTTPS válida.');
        }
        if ((string) config('paddleocr.model') !== 'PaddleOCR-VL-1.6') {
            throw new AcademicSourceOcrUnavailable('La ingesta requiere el modelo PaddleOCR-VL-1.6.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('paddleocr.access_token'))
            ->withHeaders(['Client-Platform' => 'silabos-ueb-laravel'])
            ->withoutRedirecting()
            ->connectTimeout((int) config('paddleocr.connect_timeout_seconds'))
            ->timeout((int) config('paddleocr.timeout_seconds'));
    }

    private function jobsUrl(): string
    {
        return rtrim((string) config('paddleocr.base_url'), '/').'/api/v2/ocr/jobs';
    }

    /** @return array<string, mixed> */
    private function responseData(Response $response, string $message): array
    {
        if ($response->status() === 408 || $response->status() === 429 || $response->serverError()) {
            throw new AcademicSourceOcrUnavailable($message);
        }
        if (! $response->successful()) {
            throw new AcademicSourceOcrContractException($message);
        }
        $payload = $response->json();
        if (! is_array($payload) || array_is_list($payload) || ! in_array($payload['code'] ?? 0, [0, null], true)) {
            throw new AcademicSourceOcrContractException('PaddleOCR devolvió un objeto JSON inválido.');
        }
        $data = $payload['data'] ?? null;
        if (! is_array($data) || array_is_list($data)) {
            throw new AcademicSourceOcrContractException('PaddleOCR no devolvió el campo data esperado.');
        }

        return $data;
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
}
