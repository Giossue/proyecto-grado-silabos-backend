<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResendManagedUserActivation
{
    public function __construct(
        private readonly IssueActivationInvitation $invitations,
    ) {}

    public const RESEND = 'reenvio';

    public const STILL_VALID = 'La invitación sigue vigente; podrá reenviarla cuando venza.';

    public function execute(User $target, User $actor, Request $request, string $reason = self::RESEND): User
    {
        return DB::transaction(function () use ($actor, $reason, $request, $target): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($locked->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'user' => 'La cuenta ya fue activada por su titular; no se reenvía una invitación.',
                ]);
            }
            // Un reenvío a pedido solo cuando la invitación anterior venció sin aceptarse
            // (corregir el correo sí reemplaza una vigente).
            if ($reason === self::RESEND && $this->invitations->hasActive($locked)) {
                throw ValidationException::withMessages(['user' => self::STILL_VALID]);
            }
            $this->invitations->execute($locked, $actor, $request, $reason);

            return $locked->refresh();
        });
    }
}
