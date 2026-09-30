<?php

namespace App\Modules\AiAssistance\Infrastructure\Gateways;

use App\Modules\AiAssistance\Domain\Contracts\AiChatGateway;
use App\Modules\AiAssistance\Domain\Data\AiChatInput;
use App\Modules\AiAssistance\Domain\Data\AiChatResult;

class BaselineAiChatGateway implements AiChatGateway
{
    public function version(): string
    {
        return 'contract-simulator-v1';
    }

    public function reply(AiChatInput $input): AiChatResult
    {
        $first = collect($input->evidence)->firstWhere('type', 'seccion_silabo') ?? ($input->evidence[0] ?? null);
        $answer = $first === null
            ? 'No encuentro contenido autorizado suficiente en el sílabo para responder esa pregunta.'
            : "El simulador encontró contexto en «{$first['label']}». Configure un proveedor de IA para obtener propuestas de contenido para las unidades.";

        return new AiChatResult(
            $input->requestId,
            $this->version(),
            $answer,
            $first === null ? [] : [$first['id']],
        );
    }
}
