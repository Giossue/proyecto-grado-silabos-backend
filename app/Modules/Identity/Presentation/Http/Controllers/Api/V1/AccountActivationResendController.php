<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\Actions\IssueActivationInvitation;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountActivationResendController extends Controller
{
    public function __invoke(Request $request, IssueActivationInvitation $invitations): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $user->hasVerifiedEmail()) {
            $last = ActivationInvitation::query()->where('usuario_id', $user->id)->latest(ActivationInvitation::CREATED_AT)->first();
            $issuer = $last?->emitida_por_usuario_id === null ? null : User::query()->find($last->emitida_por_usuario_id);
            $invitations->execute($user, $issuer ?? $user, $request, 'reenvio_titular');
        }

        return response()->json([
            'message' => 'Si su cuenta aún no estaba activada, se envió un enlace de activación.',
        ], 202);
    }
}
