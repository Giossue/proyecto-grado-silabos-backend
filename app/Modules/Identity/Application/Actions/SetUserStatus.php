<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
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
    ) {}

    public function execute(User $target, bool $active, User $actor, Request $request): User
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($active, $actor, $activeRole, $request, $target): User {
            if (! $active) {
                $this->ensureMayDeactivate($target);
            }

            $target->update(['activo' => $active]);

            $closedTeacherAssignments = 0;
            $closedCoordinations = [];

            if (! $active) {
                DB::table('sesiones')->where('user_id', $target->id)->delete();
                // Ningún paralelo queda a nombre de alguien que ya no está (I-39); sus
                // sílabos en curso ya tienen otro docente, porque `ensureMayDeactivate` lo exige.
                $closedTeacherAssignments = DB::table('asignaciones_paralelo')
                    ->whereIn('asignacion_rol_id', RoleAssignment::query()->where('usuario_id', $target->id)->select('id'))
                    ->where('docente_paralelo_activo', true)
                    ->update(['docente_paralelo_activo' => false]);
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
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $target;
        });
    }

    /**
     * Quien ya no está no coordina: la carrera queda sin coordinación visible y el
     * checklist y las convocatorias dejan de contarla hasta que Administración nombre
     * a otra persona desde Carreras.
     *
     * @return list<string>
     */
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
            $closed[] = $assignment->career->nombre ?? (string) $assignment->carrera_id;
        }

        return $closed;
    }

    /**
     * Desactivar no puede dejar huérfano nada: ni la administración de la institución ni
     * un sílabo en curso. Lo segundo lo resuelve Coordinación asignando
     * otro docente a sus paralelos: el sílabo sigue al paralelo (I-130).
     */
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
            ->join('silabos', 'silabos.id', '=', 'docentes_vigentes_silabo.silabo_id')
            ->join('convocatorias_carreras', 'convocatorias_carreras.id', '=', 'silabos.convocatoria_id')
            ->join('carreras', 'carreras.id', '=', 'convocatorias_carreras.carrera_id')
            ->where('docentes_vigentes_silabo.usuario_id', $target->id)
            ->whereIn('silabos.estado_silabo', ['borrador', 'en_revision', 'correccion_solicitada'])
            ->where('convocatorias_carreras.estado_convocatoria_carrera', 'abierta')
            ->groupBy('carreras.nombre_carrera')
            ->selectRaw('carreras.nombre_carrera AS carrera, COUNT(DISTINCT silabos.id) AS total')
            ->get();
        if ($inProgress->isNotEmpty()) {
            $detail = $inProgress->map(fn (object $row): string => "{$row->total} en {$row->carrera}")->implode(', ');
            throw ValidationException::withMessages([
                'active' => "Tiene sílabos en curso ({$detail}). Coordinación debe asignar otro docente a sus paralelos antes de desactivar la cuenta.",
            ]);
        }
    }
}
