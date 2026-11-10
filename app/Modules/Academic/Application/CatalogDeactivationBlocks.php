<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Support\Facades\DB;

/**
 * Motivos por los que una facultad o una carrera no puede desactivarse. La misma regla
 * la usa la acción de desactivar y la pantalla, que deshabilita el botón con el motivo.
 */
class CatalogDeactivationBlocks
{
    /**
     * @param  array<int, string>  $facultyIds
     * @return array<string, string> motivo por id de facultad
     */
    public function forFaculties(array $facultyIds): array
    {
        if ($facultyIds === []) {
            return [];
        }

        return Career::query()->whereIn('facultad_id', $facultyIds)->where('activo', true)
            ->distinct()->pluck('facultad_id')
            ->mapWithKeys(fn (string $id): array => [$id => 'Desactive primero las carreras activas de esta facultad.'])
            ->all();
    }

    /**
     * @param  array<int, string>  $careerIds
     * @return array<string, string> motivo por id de carrera
     */
    public function forCareers(array $careerIds): array
    {
        if ($careerIds === []) {
            return [];
        }
        $withInProgress = Convocation::query()->whereIn('carrera_id', $careerIds)
            ->where('estado', '!=', Convocation::STATE_CLOSED)
            ->whereHas('process', fn ($process) => $process->where('estado', '!=', SyllabusProcess::STATE_CLOSED))
            ->distinct()->pluck('carrera_id')->all();
        $blocks = [];

        foreach ($withInProgress as $id) {
            $blocks[$id] = 'La carrera participa en una convocatoria en curso. Espere a que se cierre.';
        }

        return $blocks;
    }

    /**
     * Usuarios que se retiran al desactivar cada carrera (sus roles en ella).
     *
     * @param  array<int, string>  $careerIds
     * @return array<string, array{coordinators: int, teachers: int}>
     */
    public function usersForCareers(array $careerIds): array
    {
        $counts = [];
        $rows = DB::table('asignaciones_rol')->whereIn('carrera_id', $careerIds)->where('asignacion_rol_activa', true)
            ->groupBy('carrera_id', 'rol')->selectRaw('carrera_id, rol, COUNT(*) AS total')->get();
        foreach ($rows as $row) {
            $counts[$row->carrera_id] ??= ['coordinators' => 0, 'teachers' => 0];
            $counts[$row->carrera_id][$row->rol === 'coordinador' ? 'coordinators' : 'teachers'] += (int) $row->total;
        }

        return $counts;
    }

    public function forFaculty(string $facultyId): ?string
    {
        return $this->forFaculties([$facultyId])[$facultyId] ?? null;
    }

    public function forCareer(string $careerId): ?string
    {
        return $this->forCareers([$careerId])[$careerId] ?? null;
    }
}
