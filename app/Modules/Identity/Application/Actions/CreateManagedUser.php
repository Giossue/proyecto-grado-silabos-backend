<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\PersonName;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateManagedUser
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly AssignRole $assignRole,
        private readonly RecordAuditEvent $audit,
        private readonly IssueActivationInvitation $invitations,
    ) {}

    /** @param array{nombre: string, correo_electronico: string, role_code: string, career_id?: string|null, replace_coordinator?: bool} $data */
    public function execute(array $data, User $actor, Request $request): User
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($actor, $activeRole, $data, $request): User {
            $user = User::query()->create([
                'nombre' => PersonName::normalize($data['nombre']),
                'correo_electronico' => mb_strtolower($data['correo_electronico']),
                'contrasena' => Hash::make(Str::random(64)),
                'activo' => true,
                'correo_verificado_en' => null,
            ]);
            $assignment = $this->assignRole->execute($user, $data, $actor, $request);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'usuario.creado',
                resourceType: 'usuario',
                resourceId: $user->id,
                result: 'exito',
                metadata: [
                    'initial_role' => $data['role_code'],
                ],
            );

            $this->invitations->execute($user, $actor, $request, 'alta');

            return $user;
        });
    }
}
