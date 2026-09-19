<?php

namespace App\Modules\AiAssistance\Domain\Contracts;

interface AcademicSourceEmbeddingGateway
{
    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function embed(array $inputs): array;
}
