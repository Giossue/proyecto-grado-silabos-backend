<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\Api\ResetForgottenPasswordRequest;
use App\Modules\Identity\Presentation\Http\Requests\Api\SendPasswordResetLinkRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordRecoveryController extends Controller
{
    public function sendLink(SendPasswordResetLinkRequest $request): JsonResponse
    {
        Password::sendResetLink([
            'correo_electronico' => Str::lower($request->string('email')->toString()),
        ]);

        return response()->json([
            'message' => 'Si el correo pertenece a una cuenta activa, recibirá un enlace para restablecer su contraseña.',
        ], 202);
    }

    public function reset(ResetForgottenPasswordRequest $request): JsonResponse
    {
        $status = Password::reset([
            'correo_electronico' => Str::lower($request->string('email')->toString()),
            'password' => $request->string('password')->toString(),
            'password_confirmation' => $request->string('password_confirmation')->toString(),
            'token' => $request->string('token')->toString(),
        ], function (User $user, string $password): void {
            $user->forceFill([
                'contrasena' => $password,
            ])->setRememberToken(Str::random(60));
            $user->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'El enlace para restablecer la contraseña no es válido o ya venció.',
                'errors' => ['email' => ['El enlace para restablecer la contraseña no es válido o ya venció.']],
            ], 422);
        }

        return response()->json([
            'message' => 'Contraseña restablecida. Ya puede iniciar sesión.',
        ]);
    }
}
