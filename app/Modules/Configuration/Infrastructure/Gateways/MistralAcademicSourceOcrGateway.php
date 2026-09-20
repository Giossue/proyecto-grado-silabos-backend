<?php

namespace App\Modules\Configuration\Infrastructure\Gateways;

use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrPage;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrUnavailable;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MistralAcademicSourceOcrGateway implements AcademicSourceOcrGateway
{
    public function version(): string
    {
        return (string) config('mistral.adapter_version');
    }

    public function extract(StoredObject $pdf): AcademicSourceOcrResult
    {
        $this->assertConfigured();
        $remoteFileId = null;

        try {
            $remoteFileId = $this->upload($pdf);
            $response = $this->ocr($remoteFileId);

            return $this->result($response);
        } finally {
            if (is_string($remoteFileId)) {
                $this->deleteRemoteFile($remoteFileId);
            }
        }
    }

    private function assertConfigured(): void
    {
        if (config('mistral.enabled') !== true) {
            throw new AcademicSourceOcrUnavailable('La ingesta OCR de Mistral está desactivada.');
        }

        $key = trim((string) config('mistral.api_key'));
        if ($key === '' || str_contains($key, "\r") || str_contains($key, "\n")) {
            throw new AcademicSourceOcrUnavailable('Falta configurar la clave de Mistral OCR.');
        }

        $baseUrl = (string) config('mistral.base_url');
        $parts = parse_url($baseUrl);
        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            throw new AcademicSourceOcrUnavailable('La URL de Mistral OCR no es válida.');
        }

        $model = trim((string) config('mistral.model'));
        if ($model === '' || mb_strlen($model) > 100 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw new AcademicSourceOcrUnavailable('El modelo de Mistral OCR no es válido.');
        }
    }

    private function upload(StoredObject $pdf): string
    {
        $disk = Storage::disk($pdf->disco);
        $stream = $disk->readStream($pdf->ruta_interna);
        if (! is_resource($stream)) {
            throw new AcademicSourceOcrUnavailable('No se pudo leer el PDF privado.');
        }

        try {
            $response = $this->request()
                ->attach('file', $stream, $pdf->nombre_logico)
                ->post($this->url('/files'), [
                    'purpose' => 'ocr',
                    'visibility' => 'workspace',
                ]);
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('Mistral OCR no está disponible.', previous: $exception);
        } catch (Throwable $exception) {
            throw new AcademicSourceOcrUnavailable('No fue posible enviar el PDF a Mistral OCR.', previous: $exception);
        } finally {
            fclose($stream);
        }

        $this->assertResponse($response, 'La carga del PDF en Mistral OCR falló.');
        $payload = $this->jsonObject($response);
        $fileId = $payload['id'] ?? null;
        if (! is_string($fileId) || trim($fileId) === '') {
            throw new AcademicSourceOcrContractException('Mistral no devolvió el identificador del PDF.');
        }

        return $fileId;
    }

    private function ocr(string $remoteFileId): Response
    {
        try {
            $response = $this->request()
                ->asJson()
                ->post($this->url('/ocr'), [
                    'model' => (string) config('mistral.model'),
                    'document' => ['type' => 'file', 'file_id' => $remoteFileId],
                    'table_format' => (string) config('mistral.table_format'),
                    'include_blocks' => (bool) config('mistral.include_blocks'),
                    'confidence_scores_granularity' => (string) config('mistral.confidence_scores_granularity'),
                    'include_image_base64' => (bool) config('mistral.include_image_base64'),
                ]);
        } catch (ConnectionException $exception) {
            throw new AcademicSourceOcrUnavailable('Mistral OCR no está disponible.', previous: $exception);
        } catch (Throwable $exception) {
            throw new AcademicSourceOcrUnavailable('No fue posible procesar el PDF con Mistral OCR.', previous: $exception);
        }

        $this->assertResponse($response, 'Mistral OCR devolvió un error.');

        return $response;
    }

    private function result(Response $response): AcademicSourceOcrResult
    {
        $payload = $this->jsonObject($response);
        $pages = $payload['pages'] ?? null;
        if (! is_array($pages) || $pages === []) {
            throw new AcademicSourceOcrContractException('Mistral OCR no devolvió páginas.');
        }

        $resultPages = [];
        foreach ($pages as $page) {
            if (! is_array($page)
                || ! is_int($page['index'] ?? null)
                || ! is_string($page['markdown'] ?? null)
                || trim($page['markdown']) === '') {
                throw new AcademicSourceOcrContractException('Mistral OCR devolvió una página sin Markdown.');
            }

            $resultPages[] = new AcademicSourceOcrPage(
                index: $page['index'],
                markdown: trim($page['markdown']),
                metadata: $this->pageMetadata($page),
            );
        }

        $model = $payload['model'] ?? config('mistral.model');
        if (! is_string($model) || trim($model) === '') {
            throw new AcademicSourceOcrContractException('Mistral OCR no devolvió el modelo ejecutado.');
        }

        $usage = $payload['usage_info'] ?? [];
        if (! is_array($usage)) {
            $usage = [];
        }

        return new AcademicSourceOcrResult(
            model: trim($model),
            pages: $resultPages,
            usage: $usage,
            requestId: $this->requestId($response),
        );
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function pageMetadata(array $page): array
    {
        $metadata = [];
        foreach (['images', 'tables', 'hyperlinks', 'header', 'footer', 'dimensions', 'confidence_scores', 'blocks'] as $key) {
            if (array_key_exists($key, $page) && (is_array($page[$key]) || is_string($page[$key]) || $page[$key] === null)) {
                $metadata[$key] = $page[$key];
            }
        }

        return $metadata;
    }

    private function deleteRemoteFile(string $remoteFileId): void
    {
        try {
            $this->request()
                ->delete($this->url('/files/'.rawurlencode($remoteFileId)));
        } catch (Throwable) {
            // El resultado OCR ya está persistido o el job reintentará; no se expone el fallo de limpieza.
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('mistral.api_key'))
            ->withoutRedirecting()
            ->connectTimeout((int) config('mistral.connect_timeout_seconds'))
            ->timeout((int) config('mistral.timeout_seconds'));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('mistral.base_url'), '/').$path;
    }

    private function assertResponse(Response $response, string $message): void
    {
        if ($response->status() === 429 || $response->serverError() || $response->status() === 408) {
            throw new AcademicSourceOcrUnavailable($message);
        }
        if (! $response->successful()) {
            throw new AcademicSourceOcrContractException($message);
        }
        if (strlen($response->body()) > (int) config('mistral.max_response_bytes')) {
            throw new AcademicSourceOcrContractException('La respuesta de Mistral OCR excede el tamaño permitido.');
        }
    }

    /** @return array<string, mixed> */
    private function jsonObject(Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload) || array_is_list($payload)) {
            throw new AcademicSourceOcrContractException('Mistral OCR no devolvió un objeto JSON válido.');
        }

        return $payload;
    }

    private function requestId(Response $response): ?string
    {
        $requestId = $response->header('x-request-id');

        return $requestId !== '' ? mb_substr($requestId, 0, 180) : null;
    }
}
