<?php

namespace App\Modules\AiAssistance\Infrastructure\Gateways;

use App\Modules\AiAssistance\Domain\Contracts\AcademicSourceEmbeddingGateway;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;

final class DisabledAcademicSourceEmbeddingGateway implements AcademicSourceEmbeddingGateway
{
    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function embed(array $inputs): array
    {
        throw new AiGatewayUnavailable('Los embeddings de fuentes están deshabilitados.');
    }
}
