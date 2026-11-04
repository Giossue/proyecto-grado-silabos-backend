<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinalizedChangeNotice
{
    public const MODE = 'convocatoria_finalizada';

    private const REOPEN_PATHS = ['parallel.code', 'parallel.shift', 'scheduled_subject.period', 'scheduled_subject.teaching_weeks'];

    public function __construct(
        private readonly AcademicContextSnapshot $academicContext,
        private readonly SubjectProfileFields $profiles,
        private readonly CoordinationHandover $handover,
    ) {}

    /**
     * @template TResult
     *
     * @param  string|list<string>|null  $careerIds
     * @param  Closure(): TResult  $change
     * @param  list<string>|null  $subjectIds
     * @return TResult
     */
    public function guard(Request $request, string|array|null $careerIds, Closure $change, ?array $subjectIds = null, ?string $periodId = null): mixed
    {
        $convocations = $this->confirmed($request) ? new Collection : $this->reopenable($careerIds, $periodId);
        if ($convocations->isEmpty()) {
            return $change();
        }

        return DB::transaction(function () use ($change, $convocations, $subjectIds): mixed {
            $ids = array_values(array_map('strval', $convocations->modelKeys()));
            $before = $this->reaching($ids, $subjectIds);
            $result = $change();
            if ($this->reaching($ids, $subjectIds) != $before) {
                throw $this->warning($convocations);
            }

            return $result;
        });
    }

    public function guardHandover(Request $request, string $careerId): void
    {
        $convocations = $this->confirmed($request) ? new Collection : $this->reopenable($careerId, null);
        if ($convocations->isEmpty()) {
            return;
        }
        $syllabi = Syllabus::query()->whereIn('convocatoria_id', $convocations->modelKeys())->get();
        if ($this->handover->decided($syllabi) !== []) {
            throw $this->warning($convocations);
        }
    }

    private function confirmed(Request $request): bool
    {
        return $request->boolean(InProgressWork::CONFIRMATION_FIELD);
    }

    /**
     * @param  string|list<string>|null  $careerIds
     * @return Collection<int, Convocation>
     */
    private function reopenable(string|array|null $careerIds, ?string $periodId): Collection
    {
        if ($careerIds === null && $periodId === null) {
            return new Collection;
        }
        $processes = SyllabusProcess::query()->finalizedButCurrent()->with('academicPeriod')
            ->when($periodId !== null, fn ($query) => $query->where('periodo_academico_id', $periodId))
            ->get()
            ->filter(fn (SyllabusProcess $process): bool => DeadlineExtensions::mayReopen($process));
        if ($processes->isEmpty()) {
            return new Collection;
        }

        return Convocation::query()
            ->whereIn('proceso_id', $processes->modelKeys())
            ->where('estado', Convocation::STATE_CLOSED)
            ->whereNotNull('estado_previo_cierre_convocatoria_carrera')
            ->when(is_string($careerIds), fn ($query) => $query->where('carrera_id', $careerIds))
            ->when(is_array($careerIds), fn ($query) => $query->whereIn('carrera_id', $careerIds))
            ->with('process.academicPeriod')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>  $convocationIds
     * @param  list<string>|null  $subjectIds
     * @return array<string, mixed>
     */
    private function reaching(array $convocationIds, ?array $subjectIds): array
    {
        $convocations = Convocation::query()->whereKey($convocationIds)->with('process.academicPeriod')->get()->keyBy('id');
        $syllabi = Syllabus::query()->whereIn('convocatoria_id', $convocationIds)
            ->when($subjectIds !== null, fn ($query) => $query->forSubjects($subjectIds))
            ->orderBy('id')
            ->get();
        $reaching = [];
        foreach ($syllabi as $syllabus) {
            $parallel = Parallel::query()->with('subject')->find($syllabus->paralelo_id);
            if (! $parallel instanceof Parallel) {
                continue;
            }
            $context = $this->academicContext->build($parallel, $convocations[$syllabus->convocatoria_id]->process->academicPeriod);
            $reaching[(string) $syllabus->id] = [
                'context' => array_map(fn (string $path): mixed => data_get($context, $path), [...InProgressWork::DOCUMENT_PATHS, ...self::REOPEN_PATHS]),
                'profile' => $this->profiles->values((string) $parallel->asignatura_id),
                'teacher' => TeacherAssignment::query()->where('paralelo_id', $parallel->id)->where('activo', true)->value('id'),
                'generates' => $parallel->subject->activo && $parallel->subject->genera_silabo,
            ];
        }
        foreach ($convocations as $convocation) {
            $reaching["nuevos:{$convocation->id}"] = $this->parallelsWithoutSyllabus($convocation, $subjectIds);
        }

        return $reaching;
    }

    /**
     * @param  list<string>|null  $subjectIds
     * @return list<string>
     */
    private function parallelsWithoutSyllabus(Convocation $convocation, ?array $subjectIds): array
    {
        $covered = Syllabus::query()->where('convocatoria_id', $convocation->id)->pluck('paralelo_id')->all();

        return array_values(array_map('strval', Parallel::query()
            ->whereHas('subject', fn ($query) => $query->where('carrera_id', $convocation->carrera_id)->where('activo', true)->where('genera_silabo', true))
            ->whereHas('teacherAssignments', fn ($query) => $query->where('asignaciones_paralelo.asignacion_paralelo_activa', true))
            ->when($subjectIds !== null, fn ($query) => $query->whereIn('asignatura_id', $subjectIds))
            ->whereKeyNot($covered)
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }

    /** @param  Collection<int, Convocation>  $convocations */
    private function warning(Collection $convocations): ValidationException
    {
        $period = $convocations->firstOrFail()->process->academicPeriod->codigo;

        return ValidationException::withMessages([
            'purge_required' => "La convocatoria {$period} ya finalizó. Este cambio no afecta sus sílabos: conservan los datos que tenían. Se aplicará en la próxima convocatoria, o en esta si la reabre con una prórroga. Evite estos cambios hasta abrir la próxima convocatoria, salvo que sea necesario.",
            'purge_count' => '0',
            'purge_mode' => self::MODE,
        ]);
    }
}
