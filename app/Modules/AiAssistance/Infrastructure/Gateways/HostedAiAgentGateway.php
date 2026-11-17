<?php

namespace App\Modules\AiAssistance\Infrastructure\Gateways;

use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;
use App\Modules\AiAssistance\Domain\Data\AiToolCall;
use App\Modules\AiAssistance\Domain\Data\AiToolSpec;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiProviderError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class HostedAiAgentGateway implements AiAgentGateway
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function version(): string
    {
        return 'deepseek:'.$this->model();
    }

    public function step(AiAgentRequest $request): AiAgentStep
    {
        $this->assertProvider();
        $strict = (bool) config('ai.template.strict_tools');
        $payload = $this->payload($request, $strict);
        $endpoint = $this->endpoint($strict);
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($this->apiKey())
                ->withoutRedirecting()
                ->connectTimeout((int) config('ai.hosted.connect_timeout_seconds'))
                ->timeout((int) config('ai.hosted.timeout_seconds'))
                ->retry($this->retryDelays(), when: fn (Throwable $exception): bool => $this->retryable($exception), throw: false)
                ->post($endpoint, $payload);
        } catch (ConnectionException $exception) {
            throw new AiProviderError(AiProviderError::UNAVAILABLE, 'El proveedor de IA no respondió.', $exception);
        } catch (AiProviderError $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiProviderError(AiProviderError::UNAVAILABLE, 'No fue posible contactar el proveedor de IA.', $exception);
        }
        if (! $response->successful()) {
            throw $this->failure($response);
        }
        if (strlen($response->body()) > (int) config('ai.limits.response_bytes')) {
            throw new AiContractException('La respuesta excede el tamaño permitido.');
        }
        $envelope = $response->json();
        if (! is_array($envelope) || array_is_list($envelope)) {
            throw new AiContractException('El proveedor no devolvió un objeto JSON válido.');
        }

        return $this->parse($envelope);
    }

    private function assertProvider(): void
    {
        if (strtolower(trim((string) config('ai.driver'))) !== 'deepseek') {
            throw new AiProviderError(AiProviderError::UNAVAILABLE, 'El asistente de la plantilla solo funciona con DeepSeek.');
        }
    }

    private function model(): string
    {
        $model = trim((string) (config('ai.template.model') ?: config('ai.hosted.model')));
        if ($model === '' || mb_strlen($model) > 64 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw new AiProviderError(AiProviderError::UNAVAILABLE, 'Falta configurar un modelo de IA válido.');
        }

        return $model;
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('ai.hosted.api_key'));
        if ($apiKey === '' || str_contains($apiKey, "\r") || str_contains($apiKey, "\n")) {
            throw new AiProviderError(AiProviderError::INVALID_KEY, 'Falta configurar la clave del proveedor de IA.');
        }

        return $apiKey;
    }

    private function endpoint(bool $strict): string
    {
        $baseUrl = rtrim(trim((string) config('ai.hosted.base_url')), '/');
        $parts = parse_url($baseUrl);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new AiProviderError(AiProviderError::UNAVAILABLE, 'La URL del proveedor de IA debe ser una URL HTTPS válida.');
        }
        if ($strict) {
            return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/beta/chat/completions';
        }

        return str_ends_with($baseUrl, '/chat/completions') ? $baseUrl : $baseUrl.'/chat/completions';
    }

    /** @return list<int> */
    private function retryDelays(): array
    {
        $delays = config('ai.template.retry_delays_ms');

        return is_array($delays) && $delays !== [] ? array_values(array_map('intval', $delays)) : [0];
    }

    private function retryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true);
    }

    private function failure(Response $response): AiProviderError
    {
        $status = $response->status();
        $detail = $this->providerDetail($response);

        return match (true) {
            $status === 402 => new AiProviderError(AiProviderError::NO_BALANCE, 'La cuenta del proveedor de IA no tiene saldo.'),
            $status === 401, $status === 403 => new AiProviderError(AiProviderError::INVALID_KEY, 'El proveedor de IA rechazó la clave.'),
            $status === 429 => new AiProviderError(AiProviderError::BUSY, 'El proveedor de IA recibe demasiadas solicitudes.'),
            $status >= 500 => new AiProviderError(AiProviderError::UNAVAILABLE, 'El proveedor de IA no está disponible.'),
            default => new AiProviderError(AiProviderError::REJECTED, "El proveedor de IA rechazó la solicitud ({$status}){$detail}."),
        };
    }

    private function providerDetail(Response $response): string
    {
        $message = data_get($response->json() ?? [], 'error.message');

        return is_string($message) && trim($message) !== '' ? ': '.mb_substr(trim($message), 0, 300) : '';
    }

    /** @return array<string, mixed> */
    private function payload(AiAgentRequest $request, bool $strict): array
    {
        return [
            'model' => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ...array_map($this->message(...), $request->messages),
            ],
            'tools' => array_map(fn (AiToolSpec $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters,
                    ...($strict ? ['strict' => true] : []),
                ],
            ], $request->tools),
            'tool_choice' => 'required',
            'thinking' => ['type' => 'disabled'],
            ...$this->temperature(),
            'max_tokens' => (int) config('ai.template.max_output_tokens'),
            'user_id' => $request->userTag,
        ];
    }

    /** @return array{temperature?: float} */
    private function temperature(): array
    {
        $temperature = config('ai.template.temperature');

        return is_numeric($temperature) ? ['temperature' => (float) $temperature] : [];
    }

    /**
     * @param  array{role: string, content?: string|null, tool_calls?: list<AiToolCall>, tool_call_id?: string}  $message
     * @return array<string, mixed>
     */
    private function message(array $message): array
    {
        if ($message['role'] === 'tool') {
            return ['role' => 'tool', 'tool_call_id' => $message['tool_call_id'] ?? '', 'content' => (string) ($message['content'] ?? '')];
        }
        $calls = $message['tool_calls'] ?? [];
        if ($message['role'] === 'assistant' && $calls !== []) {
            return [
                'role' => 'assistant',
                'content' => (string) ($message['content'] ?? ''),
                'tool_calls' => array_map(fn (AiToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => $call->rawArguments],
                ], $calls),
            ];
        }

        return ['role' => $message['role'], 'content' => (string) ($message['content'] ?? '')];
    }

    /** @param array<string, mixed> $envelope */
    private function parse(array $envelope): AiAgentStep
    {
        $finish = data_get($envelope, 'choices.0.finish_reason');
        if ($finish === 'insufficient_system_resource') {
            throw new AiProviderError(AiProviderError::BUSY, 'El proveedor de IA no tiene capacidad en este momento.');
        }
        if ($finish === 'length') {
            throw new AiContractException('La respuesta del proveedor quedó cortada.');
        }
        if ($finish === 'content_filter') {
            throw new AiContractException('El proveedor filtró la respuesta.');
        }
        $message = data_get($envelope, 'choices.0.message');
        if (! is_array($message)) {
            throw new AiContractException('El proveedor no devolvió un mensaje.');
        }
        $calls = [];
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $name = data_get($call, 'function.name');
            $raw = data_get($call, 'function.arguments');
            if (! is_string($name) || $name === '') {
                throw new AiContractException('El proveedor pidió una función sin nombre.');
            }
            $raw = is_string($raw) ? $raw : '';
            $calls[] = new AiToolCall((string) data_get($call, 'id', ''), $name, $this->arguments($raw), $raw);
        }
        $content = is_string($message['content'] ?? null) && trim($message['content']) !== '' ? trim($message['content']) : null;
        if ($calls === [] && $content === null) {
            throw new AiContractException('El proveedor devolvió una respuesta en blanco.');
        }

        return new AiAgentStep($this->version(), $content, $calls, [
            'prompt' => (int) data_get($envelope, 'usage.prompt_tokens', 0),
            'completion' => (int) data_get($envelope, 'usage.completion_tokens', 0),
            'cached' => (int) data_get($envelope, 'usage.prompt_cache_hit_tokens', 0),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function arguments(string $raw): ?array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ($decoded === [] || ! array_is_list($decoded)) ? $decoded : null;
    }
}
