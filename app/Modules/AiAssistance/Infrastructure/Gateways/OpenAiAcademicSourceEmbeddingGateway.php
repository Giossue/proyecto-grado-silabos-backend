<?php

namespace App\Modules\AiAssistance\Infrastructure\Gateways;

use App\Modules\AiAssistance\Domain\Contracts\AcademicSourceEmbeddingGateway;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class OpenAiAcademicSourceEmbeddingGateway implements AcademicSourceEmbeddingGateway
{
    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function embed(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        try {
            $response = $this->request()->post($this->endpoint(), [
                'model' => $this->model(),
                'input' => $inputs,
                'dimensions' => (int) config('ai.embeddings.dimensions'),
                'encoding_format' => 'float',
            ]);
        } catch (ConnectionException $exception) {
            throw new AiGatewayUnavailable('El servicio de embeddings no está disponible.', previous: $exception);
        } catch (AiGatewayUnavailable|AiContractException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new AiGatewayUnavailable('No fue posible contactar el servicio de embeddings.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new AiGatewayUnavailable('El servicio de embeddings respondió con un error temporal.');
        }
        if (strlen($response->body()) > (int) config('ai.limits.response_bytes') * 32) {
            throw new AiContractException('La respuesta de embeddings excede el tamaño permitido.');
        }

        $payload = $response->json();
        $items = is_array($payload) ? ($payload['data'] ?? null) : null;
        if (! is_array($items) || count($items) !== count($inputs)) {
            throw new AiContractException('La respuesta de embeddings no cumple el contrato esperado.');
        }

        $vectors = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_int($item['index'] ?? null) || ! is_array($item['embedding'] ?? null)) {
                throw new AiContractException('La respuesta de embeddings contiene un elemento inválido.');
            }
            $vector = $item['embedding'];
            if (count($vector) !== (int) config('ai.embeddings.dimensions')
                || array_any($vector, static fn (mixed $value): bool => ! is_int($value) && ! is_float($value))) {
                throw new AiContractException('La dimensión de un embedding no coincide con la configuración.');
            }
            $normalized = [];
            foreach ($vector as $value) {
                $normalized[] = (float) $value;
            }
            $vectors[$item['index']] = $normalized;
        }

        ksort($vectors);
        if (array_keys($vectors) !== range(0, count($inputs) - 1)) {
            throw new AiContractException('La respuesta de embeddings no contiene todos los índices esperados.');
        }

        $ordered = [];
        foreach (range(0, count($inputs) - 1) as $index) {
            $ordered[] = $vectors[$index];
        }

        return $ordered;
    }

    private function model(): string
    {
        $model = trim((string) config('ai.embeddings.model'));
        if ($model !== 'text-embedding-3-small') {
            throw new AiGatewayUnavailable('Los embeddings requieren text-embedding-3-small con 1536 dimensiones.');
        }

        return $model;
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('ai.embeddings.api_key'));
        if ($apiKey === '' || str_contains($apiKey, "\r") || str_contains($apiKey, "\n")) {
            throw new AiGatewayUnavailable('Falta configurar la clave de embeddings.');
        }

        return $apiKey;
    }

    private function endpoint(): string
    {
        $baseUrl = rtrim(trim((string) config('ai.embeddings.base_url')), '/');
        $parts = parse_url($baseUrl);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new AiGatewayUnavailable('La URL de embeddings debe ser una URL HTTPS válida.');
        }

        return str_ends_with($baseUrl, '/embeddings') ? $baseUrl : $baseUrl.'/embeddings';
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken($this->apiKey())
            ->withoutRedirecting()
            ->connectTimeout((int) config('ai.embeddings.connect_timeout_seconds'))
            ->timeout((int) config('ai.embeddings.timeout_seconds'));
    }
}
