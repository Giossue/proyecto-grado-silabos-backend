<?php

namespace App\Modules\Academic\Domain\Contracts;

interface CurricularExtractionGateway
{
    public function available(): bool;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function extract(array $input): array;
}
