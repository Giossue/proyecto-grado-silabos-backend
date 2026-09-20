<?php

namespace App\Modules\Configuration\Application\Contracts;

use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;

interface AcademicSourceFigureCropper
{
    /**
     * @param  array{left: float, top: float, right: float, bottom: float}  $bbox
     * @return array{bytes: string, mime: string}
     */
    public function crop(
        StoredObject $pdf,
        int $pageNumber,
        float $sourcePageWidth,
        float $sourcePageHeight,
        array $bbox,
    ): array;
}
