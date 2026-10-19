<?php

namespace App\Modules\Academic\Infrastructure\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class OpenParserPdfReader
{
    public function available(): bool
    {
        return config('openparser.enabled') === true
            && trim((string) config('openparser.api_key')) !== ''
            && str_starts_with((string) config('openparser.base_url'), 'https://');
    }

    /** @param  (callable(string): void)|null  $onStatus */
    public function text(string $path, string $name, ?callable $onStatus = null): string
    {
        $jobId = $this->submit($path, $name);
        $deadline = time() + (int) config('openparser.max_wait_seconds');
        while (true) {
            $state = $this->status($jobId);
            if ($onStatus !== null) {
                $onStatus($state);
            }
            if ($state === 'succeeded') {
                return $this->result($jobId);
            }
            if (in_array($state, ['failed', 'indeterminate'], true)) {
                throw new RuntimeException("El OCR no pudo leer «{$name}».");
            }
            if (time() >= $deadline) {
                throw new RuntimeException("El OCR tardó demasiado en leer «{$name}».");
            }
            sleep(max(1, (int) config('openparser.poll_delay_seconds')));
        }
    }

    private function submit(string $path, string $name): string
    {
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException("No se pudo abrir «{$name}».");
        }
        try {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->attach('request', json_encode([
                    'ocr_model' => (string) config('openparser.ocr_model'),
                    'output_format' => (string) config('openparser.output_format'),
                ], JSON_THROW_ON_ERROR), null, ['Content-Type' => 'application/json'])
                ->attach('file', $stream, $name, ['Content-Type' => 'application/pdf'])
                ->post($this->url('/parse/async'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $id = $this->json($response, 'El OCR rechazó el PDF.')['id'] ?? null;
        if (! is_string($id) || trim($id) === '') {
            throw new RuntimeException('El OCR no devolvió el identificador del trabajo.');
        }

        return trim($id);
    }

    private function status(string $jobId): string
    {
        $state = $this->json($this->request()->get($this->url('/jobs/'.rawurlencode($jobId))), 'No se pudo consultar el OCR.')['status'] ?? null;

        return is_string($state) ? $state : 'failed';
    }

    private function result(string $jobId): string
    {
        $document = $this->json(
            $this->request()->get($this->url('/jobs/'.rawurlencode($jobId).'/result'), ['format' => (string) config('openparser.output_format')]),
            'No se pudo descargar el resultado del OCR.',
        );
        if (is_string($document['markdown'] ?? null) && trim($document['markdown']) !== '') {
            return trim($document['markdown']);
        }
        $lines = [];
        foreach (is_array($document['elements'] ?? null) ? $document['elements'] : [] as $element) {
            if (is_array($element)) {
                $lines[] = $this->element($element);
            }
        }

        return trim(implode("\n\n", array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    /** @param array<string, mixed> $element */
    private function element(array $element): string
    {
        if (($element['kind'] ?? null) === 'table' && is_array($element['cells'] ?? null)) {
            $rows = [];
            foreach ($element['cells'] as $cell) {
                if (is_array($cell) && is_string($cell['text'] ?? null)) {
                    $rows[(int) ($cell['row_index'] ?? 0)][(int) ($cell['column_index'] ?? 0)] = html_entity_decode(trim($cell['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            ksort($rows);

            return implode("\n", array_map(function (array $row): string {
                ksort($row);

                return implode(' | ', $row);
            }, $rows));
        }
        foreach (['text', 'value', 'title', 'caption'] as $field) {
            if (is_string($element[$field] ?? null) && trim($element[$field]) !== '') {
                return trim($element[$field]);
            }
        }

        return '';
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
    private function json(Response $response, string $message): array
    {
        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || array_is_list($payload)) {
            Log::warning('OpenParser respondió con error.', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 1000)]);
            $reason = match (true) {
                in_array($response->status(), [401, 403], true) => 'la clave del servicio OCR no es válida',
                in_array($response->status(), [402, 429], true) => 'se agotó la cuota o el límite de uso del servicio OCR',
                $response->status() === 413 => 'el archivo es demasiado grande para el OCR',
                $response->status() >= 500 => 'el servicio OCR no está disponible en este momento',
                default => is_array($payload) ? trim((string) (data_get($payload, 'error.message') ?? $payload['detail'] ?? $payload['message'] ?? '')) : '',
            };

            throw new RuntimeException(rtrim($message, '.').' (HTTP '.$response->status().($reason !== '' ? ': '.mb_substr($reason, 0, 200) : '').').');
        }

        return $payload;
    }
}
