<?php

namespace App\Modules\AiAssistance\Domain\Contracts;

use App\Modules\AiAssistance\Domain\Data\AiChatInput;
use App\Modules\AiAssistance\Domain\Data\AiChatResult;

interface AiChatGateway
{
    public function version(): string;

    public function reply(AiChatInput $input): AiChatResult;
}
