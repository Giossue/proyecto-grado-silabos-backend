<?php

namespace App\Modules\Configuration\Domain\Contracts;

use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;

interface AcademicSourceDocumentNormalizer
{
    public function version(): string;

    public function normalize(AcademicSourceOcrResult $result): AcademicSourceOcrResult;
}
