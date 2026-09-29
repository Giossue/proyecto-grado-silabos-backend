<?php

namespace App\Modules\Documents\Domain\Data;

use Carbon\CarbonImmutable;

final readonly class SignerCertificate
{
    public function __construct(
        public string $holder,
        public string $serial,
        public string $issuer,
        public CarbonImmutable $validTo,
    ) {}
}
