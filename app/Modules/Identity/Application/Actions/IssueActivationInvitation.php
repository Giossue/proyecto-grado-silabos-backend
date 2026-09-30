<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Mail\ManagedUserActivationMail;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueActivationInvitation
{
    private const EXPIRATION_HOURS = 24;

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function hasActive(User $user): bool
    {
        return ActivationInvitation::query()
            ->where('usuario_id', $user->id)
            ->whereNull('usada_en')
            ->whereNull('invalidada_en')
            ->where('expira_en', '>', now())
            ->exists();
    }

    public function execute(User $target, User $actor, Request $request, string $reason): ActivationInvitation
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($activeRole, $actor, $reason, $target): ActivationInvitation {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);

            if (! $user->activo || $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'user' => 'La cuenta ya está activada o no está disponible para una invitación.',
                ]);
            }

            $now = now();
            ActivationInvitation::query()
                ->where('usuario_id', $user->id)
                ->whereNull('usada_en')
                ->whereNull('invalidada_en')
                ->update(['invalidada_en' => $now]);

            $token = Str::random(64);
            $invitation = ActivationInvitation::query()->create([
                'usuario_id' => $user->id,
                'huella_activacion' => hash('sha256', $token),
                'expira_en' => $now->copy()->addHours(self::EXPIRATION_HOURS),
                'emitida_por_usuario_id' => $actor->id,
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'usuario.invitacion_activacion_enviada',
                resourceType: 'usuario',
                resourceId: $user->id,
                result: 'exito',
                metadata: ['reason' => $reason],
            );

            $assignment = $user->roleAssignments()
                ->with('career')
                ->where('activo', true)
                ->first();
            $roleName = $assignment?->role->nombre_rol ?? 'Usuario';
            $careerName = $assignment?->career?->nombre;
            $activationUrl = rtrim((string) config('app.frontend_url'), '/').'/activar-cuenta?token='.urlencode($token);

            DB::afterCommit(function () use ($activationUrl, $careerName, $roleName, $user): void {
                Mail::to($user->correo_electronico)->queue(new ManagedUserActivationMail(
                    name: $user->nombre,
                    roleName: $roleName,
                    activationUrl: $activationUrl,
                    careerName: $careerName,
                ));
            });

            return $invitation;
        });
    }
}
