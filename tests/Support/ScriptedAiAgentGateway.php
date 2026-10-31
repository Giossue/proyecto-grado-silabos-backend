<?php

namespace Tests\Support;

use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;
use App\Modules\AiAssistance\Domain\Data\AiToolCall;
use RuntimeException;
use Throwable;

final class ScriptedAiAgentGateway implements AiAgentGateway
{
    /** @var list<AiAgentRequest> */
    public array $requests = [];

    private int $calls = 0;

    /**
     * @param  list<AiAgentStep|Throwable>  $script
     */
    public function __construct(private array $script) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function call(string $name, array $arguments = []): AiAgentStep
    {
        return self::calls([$name, $arguments]);
    }

    /**
     * @param  array{0: string, 1?: array<string, mixed>}  ...$calls
     */
    public static function calls(array ...$calls): AiAgentStep
    {
        static $sequence = 0;
        $toolCalls = array_map(function (array $call) use (&$sequence): AiToolCall {
            $arguments = $call[1] ?? [];
            $raw = $arguments === [] ? '{}' : json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return new AiToolCall('call-'.(++$sequence), $call[0], $arguments, $raw);
        }, $calls);

        return new AiAgentStep('guion', null, $toolCalls, ['prompt' => 100, 'completion' => 10, 'cached' => 80]);
    }

    public function version(): string
    {
        return 'guion';
    }

    public function step(AiAgentRequest $request): AiAgentStep
    {
        $this->requests[] = $request;
        $next = $this->script[$this->calls++] ?? throw new RuntimeException('El guion de la prueba se agotó.');
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<string>
     */
    public function toolResults(int $request): array
    {
        return array_values(array_map(
            fn (array $message): string => (string) ($message['content'] ?? ''),
            array_filter($this->requests[$request]->messages, fn (array $message): bool => $message['role'] === 'tool'),
        ));
    }
}
