<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateManagedUser
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly RevokeUserSessions $sessions,
    ) {}

    public function execute(string $token, string $password, Request $request): void
    {
        DB::transaction(function () use ($password, $token): void {
            $invitation = ActivationInvitation::query()
                ->lockForUpdate()
                ->where('hash_activacion', hash('sha256', $token))
                ->first();

            if ($invitation === null || $invitation->usada_en !== null || $invitation->invalidada_en !== null || $invitation->expira_en->isPast()) {
                throw ValidationException::withMessages([
                    'token' => 'El enlace de activación no es válido o ya venció.',
                ]);
            }

            $user = User::query()->lockForUpdate()->find($invitation->usuario_id);
            if ($user === null || ! $user->activo || $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'token' => 'El enlace de activación no es válido o ya fue utilizado.',
                ]);
            }

            $user->forceFill([
                'contrasena' => $password,
                'correo_verificado_en' => now(),
            ])->save();
            $invitation->forceFill(['usada_en' => now()])->save();
            $this->sessions->execute($user);
            ActivationInvitation::query()
                ->where('usuario_id', $user->id)
                ->whereNull('usada_en')
                ->whereNull('invalidada_en')
                ->where('id', '<>', $invitation->id)
                ->update(['invalidada_en' => now()]);

            $this->audit->execute(
                actorId: $user->id,
                roleAssignmentId: null,
                action: 'usuario.activado',
                resourceType: 'usuario',
                resourceId: $user->id,
                result: 'exito',
            );
        });
    }
}
