<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type CoordinatorReadiness array{
 *   process: SyllabusProcess|null,
 *   convocation: Convocation|null,
 *   curriculum_ready: bool,
 *   scheduled_total: int,
 *   scheduled_with_parallels: int,
 *   parallel_total: int,
 *   parallels_with_teacher: int,
 *   subjects_without_profile: int,
 *   template_ready: bool,
 *   faculty_logo_ready: bool,
 *   deadline_open: bool,
 *   can_open: bool
 * }
 */
final class CoordinatorConvocationReadiness
{
    public function __construct(
        private readonly InstitutionalLogos $logos,
        private readonly SubjectProfileFields $profiles,
    ) {}

    /** @return CoordinatorReadiness */
    public function inspect(string $careerId): array
    {
        $career = Career::query()->with('faculty')->withCount('subjects')->findOrFail($careerId);
        $process = SyllabusProcess::query()
            ->inProgress()
            ->with('academicPeriod')
            ->first();

        $subjects = Subject::query()
            ->where('activo', true)
            ->where('carrera_id', $careerId)
            ->when($process === null, fn (Builder $query) => $query->whereRaw('1 = 0'));
        $scheduledTotal = (clone $subjects)->count();
        $scheduledWithParallels = $process === null ? 0 : (clone $subjects)
            ->whereHas('parallels')
            ->count();

        $parallels = Parallel::query()
            ->when($process === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->whereHas('subject', fn (Builder $subject) => $subject->where('activo', true)->where('carrera_id', $careerId));
        $parallelTotal = (clone $parallels)->count();
        $parallelsWithTeacher = (clone $parallels)->whereHas('teacherAssignments', fn (Builder $assignment) => $assignment
            ->where('asignaciones_paralelo.asignacion_paralelo_activa', true)
            ->whereHas('roleAssignment', fn (Builder $role) => $role
                ->where('asignaciones_rol.asignacion_rol_activa', true)
                ->where('asignaciones_rol.rol', RoleCode::Teacher->value)
                ->where('asignaciones_rol.carrera_id', $careerId)
                ->whereHas('user', fn (Builder $user) => $user
                    ->where('usuarios.usuario_activo', true)
                    ->whereNotNull('usuarios.correo_verificado_en'))))
            ->count();

        $subjectIds = (clone $subjects)->pluck('id')->all();
        $subjectsWithoutProfile = count(array_filter($this->profiles->completeBySubject($subjectIds), fn (bool $complete): bool => ! $complete));

        $convocation = $process === null ? null : Convocation::query()
            ->where('carrera_id', $careerId)
            ->where('proceso_id', $process->id)
            ->first();
        $template = SyllabusTemplate::query()->first();
        $templateReady = $process !== null && ($template?->hasStructure() ?? false);
        $facultyLogoReady = $this->logos->facultyIsConfigured($career->faculty);
        $deadlineOpen = $process !== null
            && InstitutionDate::todayOn() <= InstitutionDate::dateOf($process->entrega_en);

        $canOpen = $process?->estado === SyllabusProcess::STATE_OPEN
            && $deadlineOpen
            && $convocation === null
            && $career->subjects_count > 0
            && $scheduledTotal > 0
            && $scheduledWithParallels === $scheduledTotal
            && $parallelTotal > 0
            && $parallelsWithTeacher === $parallelTotal
            && $subjectsWithoutProfile === 0
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
            'subjects_without_profile' => $subjectsWithoutProfile,
            'template_ready' => $templateReady,
            'faculty_logo_ready' => $facultyLogoReady,
            'deadline_open' => $deadlineOpen,
            'can_open' => $canOpen,
        ];
    }

    /**
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
            $missing[] = 'Falta registrar paralelos de las materias de la carrera.';
        }
        $withoutParallel = $readiness['scheduled_total'] - $readiness['scheduled_with_parallels'];
        if ($withoutParallel > 0) {
            $missing[] = $withoutParallel === 1
                ? 'Falta 1 materia sin paralelo.'
                : "Faltan {$withoutParallel} materias sin paralelo.";
        }
        $withoutTeacher = $readiness['parallel_total'] - $readiness['parallels_with_teacher'];
        if ($withoutTeacher > 0) {
            $missing[] = $withoutTeacher === 1
                ? 'Falta 1 paralelo sin docente.'
                : "Faltan {$withoutTeacher} paralelos sin docente.";
        }

        $withoutProfile = $readiness['subjects_without_profile'];
        if ($withoutProfile > 0) {
            $missing[] = $withoutProfile === 1
                ? 'Falta completar la ficha de 1 materia en Proyecto de carrera.'
                : "Falta completar la ficha de {$withoutProfile} materias en Proyecto de carrera.";
        }

        return $missing;
    }

    /**
     * @param  CoordinatorReadiness  $readiness
     * @return list<string>
     */
    public function blockers(array $readiness): array
    {
        $blockers = [];
        if ($readiness['process'] !== null && ! $readiness['deadline_open']) {
            $blockers[] = 'El plazo de entrega de la convocatoria institucional ya venció. Administración debe prorrogarlo.';
        }
        if (! $readiness['faculty_logo_ready']) {
            $blockers[] = 'Administración debe cargar el logo de la facultad antes de que Coordinación abra la convocatoria.';
        }

        return [...$blockers, ...$this->missingAcademicData($readiness)];
    }

    public function assertReadyToOpen(string $careerId): void
    {
        $missing = $this->blockers($this->inspect($careerId));
        if ($missing !== []) {
            throw ValidationException::withMessages(['convocation' => $missing]);
        }
    }
}
