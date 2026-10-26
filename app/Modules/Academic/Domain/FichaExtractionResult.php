<?php

namespace App\Modules\Academic\Domain;

final readonly class FichaExtractionResult
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<array{id: string, document: string, page: int|null, scope: string}>>  $sources
     * @param  list<string>  $invalid
     */
    public function __construct(public array $values, public array $sources, public array $invalid = []) {}
}
