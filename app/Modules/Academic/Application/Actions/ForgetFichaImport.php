<?php

namespace App\Modules\Academic\Application\Actions;

use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use Illuminate\Support\Facades\Storage;

final class ForgetFichaImport
{
    public function execute(FichaImport $import): void
    {
        Storage::disk('private')->deleteDirectory("importaciones-ficha/{$import->id}");
        $import->update(['estado_importacion' => FichaImport::DISCARDED, 'documentos_importacion' => []]);
    }
}
