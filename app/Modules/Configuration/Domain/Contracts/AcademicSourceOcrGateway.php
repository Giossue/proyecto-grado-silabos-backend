<?php

namespace App\Modules\Configuration\Domain\Contracts;

use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;

interface AcademicSourceOcrGateway
{
    public function version(): string;

    public function extract(StoredObject $pdf): AcademicSourceOcrResult;
}
