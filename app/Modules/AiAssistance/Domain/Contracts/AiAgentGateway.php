<?php

namespace App\Modules\AiAssistance\Domain\Contracts;

use App\Modules\AiAssistance\Domain\Data\AiAgentRequest;
use App\Modules\AiAssistance\Domain\Data\AiAgentStep;

interface AiAgentGateway
{
    public function version(): string;

    public function step(AiAgentRequest $request): AiAgentStep;
}
