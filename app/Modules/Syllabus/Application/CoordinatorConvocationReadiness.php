<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\Eloquent\Builder;

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
 *   sources_total: int,
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
            ->with(['academicPeriod', 'template'])
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

        $sourcesTotal = AcademicSource::query()
            ->where('carrera_id', $careerId)
            ->where('activo', true)
            ->count();
        $convocation = $process === null ? null : Convocation::query()
            ->where('carrera_id', $careerId)
            ->where('proceso_id', $process->id)
            ->first();
        $templateReady = $process?->template?->hasStructure() ?? false;
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
            && $sourcesTotal > 0
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
            'sources_total' => $sourcesTotal,
            'template_ready' => $templateReady,
            'faculty_logo_ready' => $facultyLogoReady,
            'deadline_open' => $deadlineOpen,
            'can_open' => $canOpen,
        ];
    }
}
