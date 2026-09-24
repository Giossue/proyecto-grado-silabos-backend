<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\PersonName;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige el nombre y el correo de una cuenta.
 *
 * El correo es la identidad con la que se inicia sesión, así que el cambio se audita con
 * el valor anterior: si alguien queda fuera del sistema, hay cómo saber qué pasó y cuándo.
 */
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

            // Una cuenta activada pertenece a una persona: que Administración le cambie el
            // correo entregaría su historial a otra. Si alguien asume sus funciones, se le
            // crea su propia cuenta. El titular sí puede actualizar su propio correo.
            if ($locked->isDirty('correo_electronico') && $previous['verified'] && $actor->id !== $locked->id) {
                throw ValidationException::withMessages([
                    'correo_electronico' => 'La cuenta ya fue activada y su correo no se puede cambiar. Si otra persona asume sus funciones, cree su cuenta y asígnele el rol.',
                ]);
            }

            if ($locked->isDirty('correo_electronico')) {
                $locked->correo_verificado_en = null;
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

            // Si el correo estaba mal y nadie ha estrenado la cuenta, el acceso viaja solo al
            // correo corregido: no hay que acordarse de reenviarlo.
            if ($previous['email'] !== $email && ! $previous['verified']) {
                $this->invitations->execute($locked, $actor, $request, 'correo_corregido');
            }

            return $locked->refresh();
        });
    }
}
