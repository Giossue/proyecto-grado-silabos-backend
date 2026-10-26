<?php

namespace App\Modules\Academic\Application\Actions;

use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;

final class ForgetFichaImport
{
    public function execute(FichaImport $import): void
    {
        $import->update(['estado_importacion' => FichaImport::DISCARDED, 'documentos_importacion' => []]);
    }
}
