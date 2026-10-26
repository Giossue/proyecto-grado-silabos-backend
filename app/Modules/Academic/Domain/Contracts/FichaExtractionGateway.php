<?php

namespace App\Modules\Academic\Domain\Contracts;

use App\Modules\Academic\Domain\FichaExtractionResult;

interface FichaExtractionGateway
{
    public function available(): bool;

    /**
     * @param  array{code: string, name: string, cycle: int|null, career?: string|null}  $subject
     * @param  list<array{key: string, label: string, columns?: list<string>, section?: array{key: string, label: string}, format?: string, template?: array<string, mixed>, components?: array<string, mixed>}>  $fields
     * @param  list<string>  $evidence
     * @return array<string, mixed>|FichaExtractionResult
     */
    public function propose(array $subject, array $fields, array $evidence): array|FichaExtractionResult;
}
