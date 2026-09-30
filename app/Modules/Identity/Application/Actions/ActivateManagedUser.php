<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateManagedUser
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function execute(string $token, string $password, Request $request): void
    {
        DB::transaction(function () use ($password, $request, $token): void {
            $invitation = ActivationInvitation::query()
                ->lockForUpdate()
                ->where('huella_activacion', hash('sha256', $token))
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
            $this->notifyIssuer($invitation, $user, $request);
        });
    }

    private function notifyIssuer(ActivationInvitation $invitation, User $user, Request $request): void
    {
        $role = $user->roleAssignments()->where('activo', true)->first()?->role->nombre_rol;
        $as = is_string($role) ? " de {$role}" : '';

        $this->notifications->execute(
            eventType: 'usuario.activado',
            deduplicationKey: "usuario.activado:{$invitation->id}",
            recipientIds: $invitation->emitida_por_usuario_id === null ? [] : [$invitation->emitida_por_usuario_id],
            title: 'Cuenta activada',
            message: "{$user->nombre} activó su cuenta{$as}.",
            resourceType: 'usuario',
            resourceId: $user->id,
        );
    }
}
