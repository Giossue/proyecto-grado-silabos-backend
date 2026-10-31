<?php

namespace App\Modules\AiAssistance\Domain\Data;

final readonly class AiAgentRequest
{
    /**
     * @param  list<array{role: string, content?: string|null, tool_calls?: list<AiToolCall>, tool_call_id?: string}>  $messages
     * @param  list<AiToolSpec>  $tools
     */
    public function __construct(
        public string $system,
        public array $messages,
        public array $tools,
        public string $userTag,
    ) {}
}
