<?php

namespace App\Modules\Academic\Domain\Contracts;

interface FichaExtractionGateway
{
    public function available(): bool;

    /**
     * @param  array{code: string, name: string, cycle: int|null}  $subject
     * @param  list<array{key: string, label: string}>  $fields
     * @param  list<string>  $evidence
     * @return array<string, string|list<string>> valor por clave de campo; cadena vacía o lista vacía si no hay sustento
     */
    public function propose(array $subject, array $fields, array $evidence): array;
}
