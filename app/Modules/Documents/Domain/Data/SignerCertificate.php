<?php

namespace App\Modules\Documents\Domain\Data;

use Carbon\CarbonImmutable;

/** I-139. Datos públicos del certificado que firmó un PDF; nunca la llave. */
final readonly class SignerCertificate
{
    public function __construct(
        public string $holder,
        public string $serial,
        public string $issuer,
        public CarbonImmutable $validTo,
    ) {}
}
