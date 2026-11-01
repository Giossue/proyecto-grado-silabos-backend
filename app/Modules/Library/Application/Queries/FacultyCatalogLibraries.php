<?php

namespace App\Modules\Library\Application\Queries;

use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Http\Request;

/** Bibliotecas de Koha donde busca quien tiene el rol activo, según la facultad de su carrera. */
class FacultyCatalogLibraries
{
    public function __construct(private readonly ActiveRole $roles) {}

    /** @return list<string> Vacío significa todas las bibliotecas. */
    public function forRequest(Request $request): array
    {
        $careerId = $this->roles->resolve($request)?->carrera_id;
        if ($careerId === null) {
            return [];
        }

        return Career::query()->with('faculty')->find($careerId)?->faculty->koha_libraries ?? [];
    }
}
