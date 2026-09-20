<?php

namespace App\Modules\Configuration\Infrastructure\Gateways;

use App\Modules\Configuration\Domain\Contracts\AcademicSourceDocumentNormalizer;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;

final class DisabledAcademicSourceDocumentNormalizer implements AcademicSourceDocumentNormalizer
{
    public function version(): string
    {
        return 'disabled';
    }

    public function normalize(AcademicSourceOcrResult $result): AcademicSourceOcrResult
    {
        return $result;
    }
}
