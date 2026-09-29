<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\ProjectDataUsage;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Calcula si una carrera puede abrir su alcance del proceso institucional vigente.
 * No persiste un estado derivado: cada consulta vuelve a comprobar las fuentes maestras
 * que OpenConvocation usará dentro de su transacción.
 *
 * @phpstan-type CoordinatorReadiness array{
 *   process: SyllabusProcess|null,
 *   convocation: Convocation|null,
 *   curriculum_ready: bool,
 *   scheduled_total: int,
 *   scheduled_with_parallels: int,
 *   parallel_total: int,
 *   parallels_with_teacher: int,
 *   project_data_missing: list<string>,
 *   template_ready: bool,
 *   faculty_logo_ready: bool,
 *   deadline_open: bool,
 *   can_open: bool
 * }
 */
final class CoordinatorConvocationReadiness
{
    public function __construct(private readonly InstitutionalLogos $logos) {}

    /**
     * @return CoordinatorReadiness
     */
    public function inspect(string $careerId): array
    {
        $career = Career::query()->with('faculty')->withCount('subjects')->findOrFail($careerId);
        $process = SyllabusProcess::query()
            ->inProgress()
            ->with('academicPeriod')
            ->first();

        $scheduledSubjects = ScheduledSubject::query()
            ->where('activo', true)
            ->whereHas('subject', fn (Builder $query) => $query->where('carrera_id', $careerId))
            ->when(
                $process !== null,
                fn (Builder $query) => $query->where('periodo_academico_id', $process->periodo_academico_id),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );
        $scheduledTotal = (clone $scheduledSubjects)->count();
        $scheduledSubjectIds = array_values(array_unique(array_filter((clone $scheduledSubjects)->pluck('asignatura_id')->all(), is_string(...))));
        $scheduledWithParallels = (clone $scheduledSubjects)->whereHas('parallels')->count();

        $parallels = Parallel::query()
            ->whereHas('scheduledSubject', fn (Builder $query) => $query
                ->where('activo', true)
                ->when(
                    $process !== null,
                    fn (Builder $period) => $period->where('periodo_academico_id', $process->periodo_academico_id),
                    fn (Builder $period) => $period->whereRaw('1 = 0'),
                )
                ->whereHas('subject', fn (Builder $subject) => $subject->where('carrera_id', $careerId)));
        $parallelTotal = (clone $parallels)->count();
        $parallelsWithTeacher = (clone $parallels)->whereHas('teacherAssignments', fn (Builder $assignment) => $assignment
            ->where('asignaciones_paralelo.docente_paralelo_activo', true)
            ->whereHas('roleAssignment', fn (Builder $role) => $role
                ->where('asignaciones_rol.asignacion_rol_activa', true)
                ->where('asignaciones_rol.rol', RoleCode::Teacher->value)
                ->where('asignaciones_rol.carrera_id', $careerId)
                ->whereHas('user', fn (Builder $user) => $user
                    ->where('usuarios.usuario_activo', true)
                    ->whereNotNull('usuarios.correo_verificado_en'))))
            ->count();

        $convocation = $process === null ? null : Convocation::query()
            ->where('carrera_id', $careerId)
            ->where('proceso_id', $process->id)
            ->first();
        $template = SyllabusTemplate::query()->first();
        $templateReady = $process !== null && ($template?->hasStructure() ?? false);
        $projectDataMissing = $this->missingProjectData($careerId, $scheduledSubjectIds, $template);
        $facultyLogoReady = $this->logos->facultyIsConfigured($career->faculty);
        $deadlineOpen = $process !== null && now()->startOfDay()->lessThanOrEqualTo($process->entrega_en->copy()->startOfDay());

        $canOpen = $process?->estado === SyllabusProcess::STATE_OPEN
            && $deadlineOpen
            && $convocation === null
            && $career->subjects_count > 0
            && $scheduledTotal > 0
            && $scheduledWithParallels === $scheduledTotal
            && $parallelTotal > 0
            && $parallelsWithTeacher === $parallelTotal
            && $projectDataMissing === []
            && $templateReady
            && $facultyLogoReady;

        return [
            'process' => $process,
            'convocation' => $convocation,
            'curriculum_ready' => $career->subjects_count > 0,
            'scheduled_total' => $scheduledTotal,
            'scheduled_with_parallels' => $scheduledWithParallels,
            'parallel_total' => $parallelTotal,
            'parallels_with_teacher' => $parallelsWithTeacher,
            'project_data_missing' => $projectDataMissing,
            'template_ready' => $templateReady,
            'faculty_logo_ready' => $facultyLogoReady,
            'deadline_open' => $deadlineOpen,
            'can_open' => $canOpen,
        ];
    }

    /**
     * Mensajes simples de lo que falta en datos académicos para abrir la convocatoria.
     *
     * @param  CoordinatorReadiness  $readiness
     * @return list<string>
     */
    public function missingAcademicData(array $readiness): array
    {
        $missing = [];
        if (! $readiness['curriculum_ready']) {
            $missing[] = 'Falta registrar la malla de la carrera con sus materias.';
        }
        if ($readiness['scheduled_total'] === 0) {
            $missing[] = 'Falta programar materias en el período.';
        }
        $withoutParallel = $readiness['scheduled_total'] - $readiness['scheduled_with_parallels'];
        if ($withoutParallel > 0) {
            $missing[] = $withoutParallel === 1
                ? 'Falta 1 materia programada sin paralelo.'
                : "Faltan {$withoutParallel} materias programadas sin paralelo.";
        }
        $withoutTeacher = $readiness['parallel_total'] - $readiness['parallels_with_teacher'];
        if ($withoutTeacher > 0) {
            $missing[] = $withoutTeacher === 1
                ? 'Falta 1 paralelo sin docente.'
                : "Faltan {$withoutTeacher} paralelos sin docente.";
        }

        return [...$missing, ...$readiness['project_data_missing']];
    }

    /**
     * Los @ProyectoCarrera que usa la plantilla son obligatorios, como la malla (I-155):
     * Coordinación los confirma para la carrera y para cada materia programada.
     *
     * @param  list<string>  $subjectIds
     * @return list<string>
     */
    private function missingProjectData(string $careerId, array $subjectIds, ?SyllabusTemplate $template): array
    {
        $used = $template === null ? [] : ProjectDataUsage::used($template);
        if ($used === []) {
            return [];
        }
        $filled = CurricularValue::query()->with('concept:id,clave_concepto')
            ->where('carrera_id', $careerId)
            ->whereHas('concept', fn (Builder $query) => $query->whereIn('clave_concepto', array_keys($used)))
            ->whereIn('asignatura_id', $subjectIds)
            ->get()
            ->filter(fn (CurricularValue $value): bool => $value->valor_curricular !== '' && $value->valor_curricular !== [])
            ->map(fn (CurricularValue $value): string => $value->concept->clave_concepto.'|'.$value->asignatura_id)
            ->flip();
        $names = Subject::query()->whereIn('id', $subjectIds)->orderBy('nombre')->pluck('nombre', 'id');
        $missing = [];
        foreach ($used as $key => $definition) {
            $without = $names->filter(fn (string $name, string $id): bool => ! $filled->has($key.'|'.$id))->values();
            if ($without->isNotEmpty()) {
                $list = $without->take(3)->implode(', ').($without->count() > 3 ? ' y '.($without->count() - 3).' más' : '');
                $missing[] = $without->count() === 1
                    ? "Falta confirmar «{$definition['label']}» en Proyecto de carrera para {$list}."
                    : "Falta confirmar «{$definition['label']}» en Proyecto de carrera para {$without->count()} materias: {$list}.";
            }
        }

        return $missing;
    }

    /** Rechaza abrir cuando faltan datos académicos; usa el mismo cálculo que ve Coordinación. */
    public function assertAcademicDataReady(string $careerId): void
    {
        $missing = $this->missingAcademicData($this->inspect($careerId));
        if ($missing !== []) {
            throw ValidationException::withMessages(['convocation' => $missing]);
        }
    }
}
