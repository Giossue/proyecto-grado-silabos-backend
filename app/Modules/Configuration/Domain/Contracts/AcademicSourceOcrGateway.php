<?php

namespace App\Modules\Configuration\Domain\Contracts;

use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrStatus;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;

interface AcademicSourceOcrGateway
{
    public function version(): string;

    public function submit(StoredObject $pdf): string;

    public function status(string $providerJobId): AcademicSourceOcrStatus;

    /** @param array<string, mixed> $result */
    public function result(string $providerJobId, array $result): AcademicSourceOcrResult;
}
