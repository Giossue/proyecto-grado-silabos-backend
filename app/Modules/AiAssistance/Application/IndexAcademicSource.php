<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;

/** Compatibilidad para invocaciones antiguas: I-146 retiró el índice RAG. */
class IndexAcademicSource
{
    public function execute(AcademicSource $source): int
    {
        return 0;
    }
}
