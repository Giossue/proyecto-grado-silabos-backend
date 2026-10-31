<?php

namespace App\Modules\AiAssistance\Infrastructure\Gateways;

use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;
use App\Modules\AiAssistance\Domain\Data\AiToolCall;

class BaselineAiAgentGateway implements AiAgentGateway
{
    public function version(): string
    {
        return 'agent-simulator-v1';
    }

    public function step(AiAgentRequest $request): AiAgentStep
    {
        $consulted = array_filter($request->messages, fn (array $message): bool => $message['role'] === 'tool');
        $call = $consulted === []
            ? new AiToolCall('simulador-1', 'ver_plantilla', [], '{}')
            : new AiToolCall('simulador-2', 'responder', ['mensaje' => 'El simulador revisó la plantilla. Configure DeepSeek para recibir propuestas de cambios.'], '{"mensaje":"El simulador revisó la plantilla. Configure DeepSeek para recibir propuestas de cambios."}');

        return new AiAgentStep($this->version(), null, [$call], ['prompt' => 0, 'completion' => 0, 'cached' => 0]);
    }
}
