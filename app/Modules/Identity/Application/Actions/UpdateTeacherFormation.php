<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateTeacherFormation
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(User $user, string $formation, Request $request): User
    {
        return DB::transaction(function () use ($formation, $request, $user): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->formacion_experiencia = trim($formation);
            if (! $locked->isDirty()) {
                return $locked;
            }
            $locked->save();

            $this->audit->execute(
                actorId: $locked->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'usuario.formacion_actualizada',
                resourceType: 'usuario',
                resourceId: $locked->id,
                result: 'exito',
            );

            return $locked;
        });
    }
}
