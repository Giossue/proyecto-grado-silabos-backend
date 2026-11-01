<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;

class LeaveActiveRole
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** Cierra el rol activo de la sesión para volver a elegir carrera o rol. */
    public function execute(User $user, Request $request): void
    {
        $current = $this->roles->resolve($request);

        if ($current === null) {
            return;
        }

        $this->audit->execute(
            actorId: $user->id,
            roleAssignmentId: $current->id,
            action: 'rol_activo.cerrado',
            resourceType: 'asignacion_rol',
            resourceId: $current->id,
            result: 'exito',
            metadata: ['role' => $current->role->codigo_rol],
        );

        $request->session()->forget('active_role_assignment_id');
        $request->attributes->remove('active_role');
        $request->session()->regenerate();
    }
}
