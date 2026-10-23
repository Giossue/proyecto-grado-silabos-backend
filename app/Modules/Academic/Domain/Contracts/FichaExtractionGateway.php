<?php

namespace App\Modules\Academic\Domain\Contracts;

interface FichaExtractionGateway
{
    public function available(): bool;

    /**
     * @param  array{code: string, name: string, cycle: int|null}  $subject
     * @param  list<array{key: string, label: string, columns?: list<string>}>  $fields
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    public function propose(array $subject, array $fields, array $evidence): array;
}
