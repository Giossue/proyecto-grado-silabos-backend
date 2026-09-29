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

    public function execute(User $target, User $actor, Request $request, string $reason = 'reenvio'): User
    {
        return DB::transaction(function () use ($actor, $reason, $request, $target): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($locked->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'user' => 'La cuenta ya fue activada por su titular; no se reenvía una invitación.',
                ]);
            }
            $this->invitations->execute($locked, $actor, $request, $reason);

            return $locked->refresh();
        });
    }
}
