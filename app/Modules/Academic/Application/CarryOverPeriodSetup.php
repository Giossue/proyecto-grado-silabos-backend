<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;

/**
 * Los paralelos, las jornadas y los docentes casi no cambian entre períodos: al abrir la
 * convocatoria institucional se copian del período anterior de cada carrera. La coordinación
 * solo corrige lo que cambió. Una materia que ya tiene paralelos en el período no se toca y
 * un docente que ya no es válido en la carrera deja el paralelo sin docente.
 */
final class CarryOverPeriodSetup
{
    /** @return array{careers: int, parallels: int, assignments: int, sources: list<array{career_id: string, source_period_id: string, parallels: int, assignments: int}>} */
    public function execute(AcademicPeriod $period): array
    {
        $summary = ['careers' => 0, 'parallels' => 0, 'assignments' => 0, 'sources' => []];

        foreach (Career::query()->where('activo', true)->get(['id']) as $career) {
            $carried = $this->carryCareer($career, $period);
            if ($carried === null) {
                continue;
            }
            $summary['careers']++;
            $summary['parallels'] += $carried['parallels'];
            $summary['assignments'] += $carried['assignments'];
            $summary['sources'][] = ['career_id' => $career->id] + $carried;
        }

        return $summary;
    }

    /** @return array{source_period_id: string, parallels: int, assignments: int}|null */
    private function carryCareer(Career $career, AcademicPeriod $period): ?array
    {
        $sourceId = Parallel::query()
            ->join('periodos_academicos', 'periodos_academicos.periodo_academico_id', '=', 'paralelos.periodo_academico_id')
            ->join('asignaturas', 'asignaturas.asignatura_id', '=', 'paralelos.asignatura_id')
            ->where('asignaturas.carrera_id', $career->id)
            ->where('periodos_academicos.fecha_inicio_periodo', '<', $period->fecha_inicio)
            ->orderByDesc('periodos_academicos.fecha_inicio_periodo')
            ->value('paralelos.periodo_academico_id');
        if (! is_string($sourceId)) {
            return null;
        }

        $alreadyScheduled = Parallel::query()
            ->where('periodo_academico_id', $period->id)
            ->pluck('asignatura_id')
            ->all();
        $sources = Parallel::query()
            ->where('periodo_academico_id', $sourceId)
            ->whereNotIn('asignatura_id', $alreadyScheduled)
            ->whereHas('subject', fn ($query) => $query->where('carrera_id', $career->id)->where('activo', true))
            ->with(['teacherAssignments' => fn ($query) => $query->where('activo', true)])
            ->get();
        if ($sources->isEmpty()) {
            return null;
        }

        $validTeacherRoles = RoleAssignment::query()
            ->effective()
            ->where('carrera_id', $career->id)
            ->where('rol', RoleCode::Teacher->value)
            ->whereHas('user', fn ($query) => $query->where('activo', true)->whereNotNull('correo_verificado_en'))
            ->pluck('id')
            ->flip();

        $parallels = 0;
        $assignments = 0;
        foreach ($sources as $source) {
            $copy = Parallel::query()->create([
                'periodo_academico_id' => $period->id,
                'asignatura_id' => $source->asignatura_id,
                'codigo' => $source->codigo,
                'jornada' => $source->jornada,
            ]);
            $parallels++;
            $roleId = $source->teacherAssignments->first()?->asignacion_rol_id;
            if ($roleId !== null && $validTeacherRoles->has($roleId)) {
                TeacherAssignment::query()->create([
                    'asignacion_rol_id' => $roleId,
                    'paralelo_id' => $copy->id,
                    'activo' => true,
                ]);
                $assignments++;
            }
        }

        return ['source_period_id' => $sourceId, 'parallels' => $parallels, 'assignments' => $assignments];
    }
}
