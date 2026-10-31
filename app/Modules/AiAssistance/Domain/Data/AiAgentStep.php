<?php

namespace App\Modules\AiAssistance\Domain\Data;

final readonly class AiAgentStep
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     * @param  array{prompt: int, completion: int, cached: int}  $usage
     */
    public function __construct(
        public string $gatewayVersion,
        public ?string $content,
        public array $toolCalls,
        public array $usage,
    ) {}
}
