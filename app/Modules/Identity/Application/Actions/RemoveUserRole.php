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
use Illuminate\Validation\ValidationException;

/**
 * Administración retira un rol de una cuenta. La asignación queda finalizada como historial;
 * la cuenta y sus otros roles no cambian. Nunca deja la cuenta sin rol (para eso se
 * deshabilita el acceso) y un docente con trabajo en curso no puede perder su rol.
 */
class RemoveUserRole
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(User $target, RoleAssignment $assignment, User $actor, Request $request): void
    {
        abort_unless($assignment->usuario_id === $target->id, 404);
        $activeRole = $this->roles->resolve($request);

        DB::transaction(function () use ($target, $assignment, $actor, $activeRole): void {
            $effective = RoleAssignment::query()
                ->effective()
                ->where('usuario_id', $target->id)
                ->lockForUpdate()
                ->get();
            if (! $effective->contains('id', $assignment->id)) {
                throw ValidationException::withMessages(['role' => 'Ese rol ya no está vigente.']);
            }
            if ($effective->count() === 1) {
                throw ValidationException::withMessages([
                    'role' => 'Es el único rol de la cuenta. Deshabilite el acceso en lugar de quitarlo.',
                ]);
            }

            $removedParallels = 0;
            if ($assignment->rol === RoleCode::Teacher->value) {
                $parallels = TeacherAssignment::query()
                    ->where('asignacion_rol_id', $assignment->id)
                    ->where('activo', true)
                    ->lockForUpdate()
                    ->get();
                foreach ($parallels as $parallel) {
                    $parallel->delete();
                }
                $removedParallels = $parallels->count();
            }

            $assignment->update(['activo' => false]);
            DB::table('sesiones')->where('user_id', $target->id)->delete();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'usuario.rol_retirado',
                resourceType: 'usuario',
                resourceId: $target->id,
                result: 'exito',
                metadata: [
                    'role' => $assignment->rol,
                    'career_id' => $assignment->carrera_id,
                    'removed_parallel_assignments' => $removedParallels,
                ],
            );
        });
    }
}
