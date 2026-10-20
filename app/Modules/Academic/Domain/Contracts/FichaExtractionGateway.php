<?php

namespace App\Modules\Academic\Domain\Contracts;

interface FichaExtractionGateway
{
    public function available(): bool;

    /**
     * @param  array{code: string, name: string, cycle: int|null}  $subject
     * @param  list<array{key: string, label: string}>  $fields
     * @param  list<string>  $evidence
     * @return array<string, mixed> valor por clave de campo tal como lo propone la IA; el trabajo lo limpia
     */
    public function propose(array $subject, array $fields, array $evidence): array;
}
