<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Operations\Application\EmailNoticeCatalog;
use App\Modules\Operations\Presentation\Http\Requests\UpdateEmailPreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configuración › Notificaciones: qué avisos recibe cada persona por correo. */
class EmailPreferencesController extends Controller
{
    public function show(Request $request, EmailNoticeCatalog $catalog): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['options' => $catalog->optionsFor($user)]);
    }

    public function update(UpdateEmailPreferencesRequest $request, EmailNoticeCatalog $catalog): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $user->forceFill(['preferencias_correo' => [...(array) $user->preferencias_correo, ...$request->preferences()]])->save();

        return response()->json(['options' => $catalog->optionsFor($user->fresh() ?? $user)]);
    }
}
