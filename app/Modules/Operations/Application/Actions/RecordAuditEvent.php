<?php

namespace App\Modules\Operations\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;

class RecordAuditEvent
{
    /** @param array<string, bool|float|int|string|null> $metadata */
    public function execute(
        ?string $actorId,
        ?string $roleAssignmentId,
        string $action,
        string $resourceType,
        ?string $resourceId,
        string $result,
        array $metadata = [],
    ): AuditEvent {
        $assignment = $roleAssignmentId === null ? null : RoleAssignment::query()->with('career:id,nombre')->find($roleAssignmentId);

        return AuditEvent::query()->create([
            'actor_usuario_id' => $actorId,
            'actor_nombre' => $actorId === null ? null : User::query()->whereKey($actorId)->value('nombre_usuario'),
            'actor_rol' => $assignment?->role->nombre_rol,
            'actor_carrera' => $assignment?->career?->nombre,
            'asignacion_rol_id' => $roleAssignmentId,
            'accion' => $action,
            'tipo_recurso' => $resourceType,
            'recurso_id' => $resourceId,
            'resultado' => $result,
            'metadatos' => $metadata,
            'ocurrido_en' => now(),
        ]);
    }
}
