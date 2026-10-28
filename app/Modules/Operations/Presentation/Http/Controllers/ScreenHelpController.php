<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\ScreenHelpCatalog;
use App\Modules\Operations\Presentation\Http\Requests\AvailableScreenHelpRequest;
use App\Modules\Operations\Presentation\Http\Requests\ShowScreenHelpRequest;
use Illuminate\Http\JsonResponse;

class ScreenHelpController extends Controller
{
    public function show(ShowScreenHelpRequest $request, ScreenHelpCatalog $catalog): JsonResponse
    {
        return response()->json(['help' => $catalog->forScreen($request->string('screen')->toString())]);
    }

    public function available(AvailableScreenHelpRequest $request, ActiveRole $roles, ScreenHelpCatalog $catalog): JsonResponse
    {
        $prefix = match ($roles->resolve($request)?->role->codigo_rol) {
            'administrador' => '/admin',
            'coordinador' => '/coordinacion',
            'docente' => '/docente',
            'bibliotecario' => '/biblioteca',
            default => null,
        };

        return response()->json(['help' => $prefix === null ? [] : $catalog->underPrefix($prefix)]);
    }
}
