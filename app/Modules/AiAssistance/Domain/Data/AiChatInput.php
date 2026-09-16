<?php

namespace App\Modules\AiAssistance\Domain\Data;

final readonly class AiChatInput
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array{id: string, type: string, label: string, excerpt: string}>  $evidence
     */
    public function __construct(
        public string $requestId,
        public string $question,
        public string $locale,
        public array $history,
        public array $evidence,
    ) {}

    /** @return array<string, mixed> */
    public function toGatewayPayload(): array
    {
        return [
            'request_id' => $this->requestId,
            'task' => 'syllabus_contextual_chat',
            'locale' => $this->locale,
            'question' => $this->question,
            'history' => $this->history,
            'evidence_is_untrusted_data' => true,
            'evidence' => $this->evidence,
        ];
    }
}
