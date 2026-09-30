<?php

namespace App\Modules\AiAssistance\Infrastructure\Gateways;

use App\Modules\AiAssistance\Domain\Contracts\AiChatGateway;
use App\Modules\AiAssistance\Domain\Data\AiChatInput;
use App\Modules\AiAssistance\Domain\Data\AiChatResult;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;

class DisabledAiChatGateway implements AiChatGateway
{
    public function version(): string
    {
        return 'disabled-v1';
    }

    public function reply(AiChatInput $input): AiChatResult
    {
        throw new AiGatewayUnavailable('La asistencia de IA está deshabilitada en este entorno.');
    }
}
