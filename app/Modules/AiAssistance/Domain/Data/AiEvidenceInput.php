<?php

namespace App\Modules\AiAssistance\Domain\Data;

final readonly class AiEvidenceInput
{
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $sourceName,
        public string $excerpt,
        public string $fingerprint,
        public string $type = 'fuente_academica',
    ) {}

    /** @return array<string, string> */
    public function toGatewayPayload(): array
    {
        $reference = $this->type === 'seccion_silabo'
            ? ['field_id' => $this->sourceId]
            : ['source_id' => $this->sourceId];

        return [
            'evidence_id' => $this->id,
            'type' => $this->type,
            ...$reference,
            'label' => $this->sourceName,
            'excerpt' => $this->excerpt,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
