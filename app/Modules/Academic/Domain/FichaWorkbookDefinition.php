<?php

namespace App\Modules\Academic\Domain;

final readonly class FichaWorkbookDefinition
{
    /**
     * @param  list<array{id: string, key: string, label: string, section: string, columns: list<string>, sheet: string, definition_hash: string}>  $fields
     * @param  list<array{id: string, code: string, name: string, cycle: int|null}>  $subjects
     */
    public function __construct(public string $careerId, public string $careerName, public array $fields, public array $subjects) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->careerId, $this->fields, $this->subjects], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
