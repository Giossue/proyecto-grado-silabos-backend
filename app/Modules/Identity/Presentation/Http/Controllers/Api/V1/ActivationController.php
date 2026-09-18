<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Actions\ActivateManagedUser;
use App\Modules\Identity\Presentation\Http\Requests\ActivateManagedUserRequest;
use Illuminate\Http\JsonResponse;

class ActivationController extends Controller
{
    public function activate(ActivateManagedUserRequest $request, ActivateManagedUser $action): JsonResponse
    {
        $action->execute(
            $request->string('token')->toString(),
            $request->string('password')->toString(),
            $request,
        );

        return response()->json(['message' => 'Cuenta activada. Ya puede iniciar sesión.']);
    }
}
