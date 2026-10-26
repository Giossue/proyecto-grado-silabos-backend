<?php

namespace App\Modules\Academic\Domain;

final readonly class FichaWorkbookPreview
{
    /** @param list<array<string, mixed>> $subjects */
    public function __construct(public FichaWorkbookDefinition $definition, public string $fileHash, public array $subjects) {}
}
