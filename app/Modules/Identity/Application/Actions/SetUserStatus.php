<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetUserStatus
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly RevokeUserSessions $sessions,
    ) {}

    public function execute(User $target, bool $active, User $actor, Request $request): User
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($active, $actor, $activeRole, $target): User {
            if (! $active) {
                $this->ensureMayDeactivate($target);
            }

            $target->update(['activo' => $active]);

            $closedTeacherAssignments = 0;
            $closedCoordinations = [];

            if (! $active) {
                $this->sessions->execute($target);
                $closedTeacherAssignments = DB::table('asignaciones_paralelo')
                    ->whereIn('asignacion_rol_id', RoleAssignment::query()->where('usuario_id', $target->id)->select('id'))
                    ->where('asignacion_paralelo_activa', true)
                    ->update(['asignacion_paralelo_activa' => false]);
                $closedCoordinations = $this->closeCoordinations($target);
            }

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: $active ? 'usuario.activado' : 'usuario.desactivado',
                resourceType: 'usuario',
                resourceId: $target->id,
                result: 'exito',
                metadata: [
                    'closed_teacher_assignments' => $closedTeacherAssignments,
                    'closed_coordinations' => implode(', ', $closedCoordinations),
                ],
            );

            return $target;
        });
    }

    /** @return list<string> */
    private function closeCoordinations(User $target): array
    {
        $closed = [];
        $assignments = RoleAssignment::query()
            ->effective()
            ->with('career:id,nombre')
            ->where('usuario_id', $target->id)
            ->where('rol', RoleCode::Coordinator->value)
            ->lockForUpdate()
            ->get();
        foreach ($assignments as $assignment) {
            $assignment->update(['activo' => false]);
            $closed[] = $assignment->career?->label() ?? (string) $assignment->carrera_id;
        }

        return $closed;
    }

    private function ensureMayDeactivate(User $target): void
    {
        $isAdministrator = RoleAssignment::query()
            ->effective()
            ->where('usuario_id', $target->id)
            ->where('rol', RoleCode::Administrator->value)
            ->exists();
        if ($isAdministrator) {
            $otherAdministrators = RoleAssignment::query()
                ->effective()
                ->where('usuario_id', '!=', $target->id)
                ->where('rol', RoleCode::Administrator->value)
                ->whereHas('user', fn ($query) => $query->where('activo', true))
                ->exists();
            if (! $otherAdministrators) {
                throw ValidationException::withMessages([
                    'active' => 'Es la única cuenta de administración activa. Nombre otra antes de desactivarla.',
                ]);
            }
        }

        $inProgress = DB::table('docentes_vigentes_silabo')
            ->join('silabos', 'silabos.silabo_id', '=', 'docentes_vigentes_silabo.silabo_id')
            ->join('convocatorias_carreras', 'convocatorias_carreras.convocatoria_carrera_id', '=', 'silabos.convocatoria_carrera_id')
            ->join('carreras', 'carreras.carrera_id', '=', 'convocatorias_carreras.carrera_id')
            ->where('docentes_vigentes_silabo.usuario_id', $target->id)
            ->whereIn('silabos.estado_silabo', ['borrador', 'en_revision', 'correccion_solicitada'])
            ->where('convocatorias_carreras.estado_convocatoria_carrera', 'abierta')
            ->groupBy('carreras.carrera_id')
            ->selectRaw('carreras.carrera_id AS carrera_id, COUNT(DISTINCT silabos.silabo_id) AS total')
            ->get();
        if ($inProgress->isNotEmpty()) {
            $careers = Career::query()->whereKey($inProgress->pluck('carrera_id'))->get()->keyBy('id');
            $detail = $inProgress->map(fn (object $row): string => "{$row->total} en ".($careers->get($row->carrera_id)?->label() ?? ''))->implode(', ');
            throw ValidationException::withMessages([
                'active' => "Tiene sílabos en curso ({$detail}). Coordinación debe asignar otro docente a sus paralelos antes de desactivar la cuenta.",
            ]);
        }
    }
}
