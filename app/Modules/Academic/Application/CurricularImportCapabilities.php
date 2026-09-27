<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Contracts\CurricularExtractionGateway;

final class CurricularImportCapabilities
{
    public function __construct(private readonly CurricularExtractionGateway $gateway) {}

    /** @return array{pdf: bool, xlsx: bool, extraction: bool} */
    public function toArray(): array
    {
        return [
            'pdf' => (bool) config('openparser.enabled') && filled(config('openparser.api_key')),
            'xlsx' => class_exists(\ZipArchive::class),
            'extraction' => $this->gateway->available(),
        ];
    }
}
