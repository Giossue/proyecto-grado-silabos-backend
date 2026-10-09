<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Coordinación quita a un docente de su carrera: cierra su rol docente en la carrera y
 * borra sus asignaciones de paralelo vigentes. La cuenta y sus otros roles no cambian;
 * los sílabos de esos paralelos se conservan sin docente hasta que se asigne otro.
 */
class RemoveCareerTeacher
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly RevokeUserSessions $sessions,
    ) {}

    /** @return array{removed_parallel_assignments: int} */
    public function execute(User $target, User $actor, Request $request): array
    {
        Gate::forUser($actor)->authorize('updateCareerTeacher', $target);
        $activeRole = $this->roles->resolve($request);
        abort_unless($activeRole?->carrera_id !== null, 403);
        $careerId = $activeRole->carrera_id;

        return DB::transaction(function () use ($target, $actor, $activeRole, $careerId): array {
            $roleAssignment = RoleAssignment::query()
                ->where('usuario_id', $target->id)
                ->where('rol', RoleCode::Teacher->value)
                ->where('carrera_id', $careerId)
                ->where('activo', true)
                ->lockForUpdate()
                ->firstOrFail();

            $parallelAssignments = TeacherAssignment::query()
                ->where('asignacion_rol_id', $roleAssignment->id)
                ->where('activo', true)
                ->lockForUpdate()
                ->get();
            foreach ($parallelAssignments as $assignment) {
                $assignment->delete();
            }

            $roleAssignment->update(['activo' => false]);
            if (! RoleAssignment::query()->effective()->where('usuario_id', $target->id)->exists()) {
                $this->sessions->execute($target);
            }

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'usuario.docente_retirado',
                resourceType: 'usuario',
                resourceId: $target->id,
                result: 'exito',
                metadata: ['carrera_id' => $careerId, 'removed_parallel_assignments' => $parallelAssignments->count()],
            );

            return ['removed_parallel_assignments' => $parallelAssignments->count()];
        });
    }
}
