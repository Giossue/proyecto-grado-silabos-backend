<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * @phpstan-type Plan array{
 *     period: bool,
 *     subjects: list<string>,
 *     teachers: list<string>,
 *     parallels: list<string>,
 *     handovers: list<string>,
 *     counts: array{to_draft: int, restarted: int, to_coordination: int},
 * }
 */
final class ReopenCatchUp
{
    private const RESTARTED_WHEN_PARALLEL_CHANGES = ['en_revision', 'aprobado'];

    public function __construct(
        private readonly InProgressWork $work,
        private readonly CoordinationHandover $handover,
    ) {}

    /** @return list<string> */
    public function convocationsToReopen(SyllabusProcess $closed): array
    {
        return array_values(array_map('strval', Convocation::query()
            ->where('proceso_id', $closed->id)
            ->where('estado', Convocation::STATE_CLOSED)
            ->whereNotNull('estado_previo_cierre_convocatoria_carrera')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all()));
    }

    /**
     * @param  Plan  $plan
     * @param  list<string>  $convocationIds
     */
    public function apply(array $plan, array $convocationIds, string $periodId): void
    {
        if ($plan['period']) {
            $this->work->regenerate(null, $periodId, 'cambió el período académico', skipIncomplete: true);
        }
        if ($plan['subjects'] !== []) {
            $this->work->regenerate(null, null, 'la materia dejó de generar sílabo', null, $plan['subjects'], skipIncomplete: true);
        }
        foreach ($plan['teachers'] as $parallelId) {
            $this->work->restartForParallel($parallelId);
        }
        foreach ($plan['parallels'] as $parallelId) {
            $this->work->restartSentForParallel($parallelId);
        }
        $this->work->catchUp(array_values(array_map('strval', Convocation::query()->whereKey($convocationIds)->pluck('carrera_id')->all())));
        foreach ($plan['parallels'] as $parallelId) {
            $this->work->refreshParallel($parallelId);
        }
        foreach ($plan['handovers'] as $careerId) {
            $this->handover->returnApprovals($careerId);
        }
    }

    /**
     * @param  list<string>  $convocationIds
     * @return Plan
     */
    public function plan(array $convocationIds, ?CarbonInterface $finalizedAt): array
    {
        $syllabi = Syllabus::query()->whereIn('convocatoria_id', $convocationIds)
            ->with(['convocation.process.academicPeriod', 'parallel.subject'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $period = $syllabi->contains(fn (Syllabus $syllabus): bool => $this->periodChanged($syllabus));
        $subjects = $syllabi->filter(fn (Syllabus $syllabus): bool => ! $this->generates($syllabus));
        $teachers = $syllabi->filter(fn (Syllabus $syllabus): bool => $this->teacherChanged($syllabus, $finalizedAt));
        $parallels = $syllabi->filter(fn (Syllabus $syllabus): bool => $this->parallelChanged($syllabus));
        $restarted = $period
            ? $syllabi
            : $subjects->merge($teachers)->merge($parallels->filter(fn (Syllabus $syllabus): bool => in_array($syllabus->estado, self::RESTARTED_WHEN_PARALLEL_CHANGES, true)))->unique('id');
        $restartedIds = $restarted->pluck('id')->all();
        $remaining = $syllabi->reject(fn (Syllabus $syllabus): bool => in_array($syllabus->id, $restartedIds, true));
        $toDraft = $this->work->returningToDraft($remaining);
        $handovers = $this->handovers($syllabi, $finalizedAt);
        $toCoordination = $remaining
            ->reject(fn (Syllabus $syllabus): bool => in_array((string) $syllabus->id, $toDraft, true))
            ->filter(fn (Syllabus $syllabus): bool => in_array((string) $syllabus->convocation->carrera_id, $handovers, true));

        return [
            'period' => $period,
            'subjects' => $this->ids($subjects->map(fn (Syllabus $syllabus): string => (string) $syllabus->parallel->asignatura_id)),
            'teachers' => $this->ids($teachers->pluck('paralelo_id')),
            'parallels' => $this->ids($parallels->pluck('paralelo_id')),
            'handovers' => $handovers,
            'counts' => [
                'to_draft' => count($toDraft),
                'restarted' => $restarted->filter(fn (Syllabus $syllabus): bool => in_array($syllabus->estado, InProgressWork::SENT_STATES, true))->count(),
                'to_coordination' => count($this->handover->decided($toCoordination)),
            ],
        ];
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return list<string>
     */
    private function ids(Collection $ids): array
    {
        return array_values(array_map('strval', $ids->unique()->values()->all()));
    }

    private function periodChanged(Syllabus $syllabus): bool
    {
        $period = $syllabus->convocation->process->academicPeriod;
        $stored = $syllabus->contexto_academico;

        return $this->differs($stored, 'scheduled_subject.period', $period->codigo)
            || $this->differs($stored, 'scheduled_subject.teaching_weeks', $period->semanas_lectivas);
    }

    private function generates(Syllabus $syllabus): bool
    {
        $subject = $syllabus->parallel->subject;

        return $subject->activo && $subject->genera_silabo;
    }

    private function parallelChanged(Syllabus $syllabus): bool
    {
        $stored = $syllabus->contexto_academico;

        return $this->differs($stored, 'parallel.code', $syllabus->parallel->codigo)
            || $this->differs($stored, 'parallel.shift', $syllabus->parallel->jornada);
    }

    /** @param  array<string, mixed>  $stored */
    private function differs(array $stored, string $path, mixed $current): bool
    {
        return data_get($stored, $path, $current) != $current;
    }

    private function teacherChanged(Syllabus $syllabus, ?CarbonInterface $finalizedAt): bool
    {
        $assignment = TeacherAssignment::query()->where('paralelo_id', $syllabus->paralelo_id)->where('activo', true)->with('roleAssignment.user')->first();
        if ($assignment === null || $finalizedAt === null || $assignment->asignado_en->lessThan($finalizedAt->startOfSecond())) {
            return false;
        }
        if (in_array($syllabus->estado, InProgressWork::SENT_STATES, true)) {
            $author = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->value('enviado_por');

            return $author !== null && $author !== $assignment->roleAssignment->usuario_id;
        }

        return $syllabus->estado === 'borrador'
            && data_get($syllabus->contexto_academico, ProcessClosing::CLOSED_DOCUMENT.'.identification.email') !== $assignment->roleAssignment->user->correo_electronico;
    }

    /**
     * @param  Collection<int, Syllabus>  $syllabi
     * @return list<string>
     */
    private function handovers(Collection $syllabi, ?CarbonInterface $finalizedAt): array
    {
        if ($finalizedAt === null) {
            return [];
        }
        $careerIds = $this->ids($syllabi->map(fn (Syllabus $syllabus): string => (string) $syllabus->convocation->carrera_id));

        return $this->ids(AuditEvent::query()
            ->where('accion', 'academico.coordinacion.reemplazada')
            ->where('tipo_recurso', 'carrera')
            ->whereIn('recurso_id', $careerIds)
            ->where('ocurrido_en', '>=', $finalizedAt->startOfSecond())
            ->pluck('recurso_id'));
    }
}
