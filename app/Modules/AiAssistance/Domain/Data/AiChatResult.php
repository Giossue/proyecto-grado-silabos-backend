<?php

namespace App\Modules\AiAssistance\Domain\Data;

final readonly class AiChatResult
{
    /** @param list<string> $citationIds */
    public function __construct(
        public string $requestId,
        public string $gatewayVersion,
        public string $answer,
        public array $citationIds,
    ) {}
}
