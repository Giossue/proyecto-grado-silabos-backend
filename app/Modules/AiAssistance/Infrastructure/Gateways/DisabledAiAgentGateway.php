<?php

namespace App\Modules\AiAssistance\Infrastructure\Gateways;

use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;
use App\Modules\AiAssistance\Domain\Exceptions\AiProviderError;

class DisabledAiAgentGateway implements AiAgentGateway
{
    public function version(): string
    {
        return 'disabled-v1';
    }

    public function step(AiAgentRequest $request): AiAgentStep
    {
        throw new AiProviderError(AiProviderError::UNAVAILABLE, 'La asistencia de IA está deshabilitada en este entorno.');
    }
}
