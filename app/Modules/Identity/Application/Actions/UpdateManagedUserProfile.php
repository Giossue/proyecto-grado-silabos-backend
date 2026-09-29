<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\PersonName;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateManagedUserProfile
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ResendManagedUserActivation $invitations,
    ) {}

    /** @param array{nombre: string, correo_electronico: string} $data */
    public function execute(User $target, array $data, User $actor, Request $request): User
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($activeRole, $actor, $data, $request, $target): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $previous = [
                'name' => $locked->nombre,
                'email' => $locked->correo_electronico,
                'verified' => $locked->hasVerifiedEmail(),
            ];
            $email = mb_strtolower($data['correo_electronico']);

            $name = PersonName::normalize($data['nombre']);

            $locked->fill(['nombre' => $name, 'correo_electronico' => $email]);
            if (! $locked->isDirty()) {
                return $locked;
            }

            if ($locked->isDirty('correo_electronico') && $previous['verified']) {
                throw ValidationException::withMessages([
                    'correo_electronico' => 'La cuenta ya fue activada y su correo no se puede cambiar. Si otra persona asume sus funciones, cree su cuenta y asígnele el rol.',
                ]);
            }

            $locked->save();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'usuario.perfil_actualizado',
                resourceType: 'usuario',
                resourceId: $locked->id,
                result: 'exito',
                metadata: [
                    'previous_name' => $previous['name'],
                    'previous_email' => $previous['email'],
                    'name_changed' => $previous['name'] !== $name,
                    'email_changed' => $previous['email'] !== $email,
                ],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            if ($previous['email'] !== $email && ! $previous['verified']) {
                $this->invitations->execute($locked, $actor, $request, 'correo_corregido');
            }

            return $locked->refresh();
        });
    }
}
