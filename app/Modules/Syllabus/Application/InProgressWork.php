<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InProgressWork
{
    public const CONFIRMATION_FIELD = 'confirm_purge';

    public const DEFAULT_REASON = 'cambió la base institucional (malla, período académico o plantilla)';

    public function __construct(
        private readonly GenerateConvocationSyllabi $generator,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    /**
     * @param  string|list<string>|null  $careerIds
     * @param  list<string>|null  $subjectIds
     */
    public function count(string|array|null $careerIds = null, ?string $periodId = null, ?string $subjectId = null, ?array $subjectIds = null): int
    {
        return $this->inProgress($careerIds, $periodId, $subjectId, $subjectIds)->count();
    }

    /**
     * @param  string|list<string>|null  $careerIds
     * @param  list<string>|null  $subjectIds
     */
    public function requireConfirmation(Request $request, string|array|null $careerIds = null, ?string $periodId = null, ?string $subjectId = null, ?array $subjectIds = null): int
    {
        $count = $this->count($careerIds, $periodId, $subjectId, $subjectIds);
        if ($count === 0) {
            return 0;
        }

        if (! $request->boolean(self::CONFIRMATION_FIELD)) {
            throw ValidationException::withMessages([
                'purge_required' => $count === 1
                    ? 'Este cambio reemplazará 1 sílabo. Se eliminarán su borrador, revisiones y análisis de IA, y se generará nuevamente desde la base corregida.'
                    : "Este cambio reemplazará {$count} sílabos. Se eliminarán sus borradores, revisiones y análisis de IA, y se generarán nuevamente desde la base corregida.",
                'purge_count' => (string) $count,
            ]);
        }

        return $count;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @param  string|list<string>|null  $careerIds
     * @param  list<string>|null  $subjectIds
     * @return TResult
     */
    public function replace(
        Request $request,
        Closure $change,
        string|array|null $careerIds = null,
        ?string $periodId = null,
        ?string $reason = null,
        ?string $subjectId = null,
        ?array $subjectIds = null,
    ): mixed {
        return DB::transaction(function () use ($careerIds, $change, $periodId, $reason, $request, $subjectId, $subjectIds): mixed {
            $count = $this->requireConfirmation($request, $careerIds, $periodId, $subjectId, $subjectIds);
            $result = $change();
            if ($count > 0) {
                $this->regenerate(
                    $careerIds,
                    $periodId,
                    $reason,
                    $request->attributes->getString('correlation_id') ?: null,
                    $subjectId,
                    $subjectIds,
                );
            }

            return $result;
        });
    }

    /**
     * @param  string|list<string>|null  $careerIds
     * @param  list<string>|null  $subjectIds
     * @return array{deleted: int, generated: int}
     */
    public function regenerate(
        string|array|null $careerIds = null,
        ?string $periodId = null,
        ?string $reason = null,
        ?string $correlationId = null,
        ?string $subjectId = null,
        ?array $subjectIds = null,
    ): array {
        $syllabi = $this->inProgress($careerIds, $periodId, $subjectId, $subjectIds)->with('convocation:id,carrera_id')->lockForUpdate()
            ->get(['id', 'convocatoria_id', 'paralelo_id', 'contexto_academico']);
        if ($syllabi->isEmpty()) {
            return ['deleted' => 0, 'generated' => 0];
        }

        $convocationIds = $syllabi->pluck('convocatoria_id')->unique()->values()->all();
        $syllabusIds = array_values(array_map('strval', $syllabi->pluck('id')->all()));
        $teachers = $this->currentTeachers($syllabusIds);
        $this->deleteAggregates($syllabusIds);
        foreach ($syllabi as $syllabus) {
            $subject = $syllabus->academicSubjectName();
            $this->notifications->execute(
                eventType: 'silabo.regenerado',
                deduplicationKey: "silabo.regenerado:{$syllabus->id}:".Str::uuid(),
                recipientIds: $teachers[$syllabus->id] ?? [],
                title: "Su sílabo de {$subject} se generó de nuevo",
                message: "Su sílabo de {$subject} se generó de nuevo porque ".($reason ?? self::DEFAULT_REASON)
                    .'. Se perdió el contenido anterior (borrador, revisiones y análisis de IA); debe completarlo otra vez.',
                resourceType: 'carrera',
                resourceId: (string) $syllabus->convocation->carrera_id,
                correlationId: $correlationId,
            );
        }

        $generated = 0;
        $convocations = Convocation::query()
            ->whereKey($convocationIds)
            ->with('process')
            ->lockForUpdate()
            ->get();
        foreach ($convocations as $convocation) {
            $generated += $this->generator->execute($convocation, $subjectId, $subjectIds);
        }

        return ['deleted' => count($syllabusIds), 'generated' => $generated];
    }

    /**
     * @param  list<string>  $syllabusIds
     * @return array<string, list<string>>
     */
    private function currentTeachers(array $syllabusIds): array
    {
        return DB::table('docentes_vigentes_silabo')
            ->whereIn('silabo_id', $syllabusIds)
            ->distinct()
            ->get(['silabo_id', 'usuario_id'])
            ->groupBy('silabo_id')
            ->map(fn ($rows): array => array_values(array_map('strval', $rows->pluck('usuario_id')->all())))
            ->all();
    }

    /** @param list<string> $syllabusIds */
    private function deleteAggregates(array $syllabusIds): void
    {
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        $revisionIds = DB::table('revisiones_silabo')->whereIn('silabo_id', $syllabusIds)->pluck('id')->all();
        foreach ($syllabusIds as $syllabusId) {
            DB::table('historial_ia')->where('sesion_id', 'like', "silabo:{$syllabusId}:%")->delete();
        }

        $exports = DB::table('artefactos_exportacion')
            ->whereIn('revision_silabo_id', $revisionIds)
            ->get(['id', 'objeto_docx_id', 'objeto_pdf_id']);
        $signatures = DB::table('firmas_documento')->whereIn('artefacto_exportacion_id', $exports->pluck('id')->all());
        $objectIds = $exports->flatMap(fn ($export) => [$export->objeto_docx_id, $export->objeto_pdf_id])
            ->merge((clone $signatures)->pluck('objeto_almacenado_id'))
            ->filter()->unique()->values()->all();
        $objects = DB::table('objetos_almacenados')->whereIn('id', $objectIds)->get(['disco', 'ruta_interna']);
        $signatures->delete();
        DB::table('artefactos_exportacion')->whereIn('revision_silabo_id', $revisionIds)->delete();
        DB::table('objetos_almacenados')->whereIn('id', $objectIds)->delete();
        DB::afterCommit(function () use ($objects): void {
            foreach ($objects as $object) {
                Storage::disk($object->disco)->delete($object->ruta_interna);
            }
        });

        $observationIds = DB::table('observaciones_revision')->whereIn('revision_silabo_id', $revisionIds)->pluck('id')->all();
        DB::table('revisiones_silabo')->whereIn('id', $revisionIds)->update([
            'revision_anterior_id' => null,
            'reapertura_id' => null,
        ]);
        DB::table('reaperturas')->whereIn('revision_aprobada_id', $revisionIds)->delete();
        DB::table('aprobaciones')->whereIn('revision_silabo_id', $revisionIds)->delete();
        DB::table('solicitudes_correccion')->whereIn('revision_silabo_id', $revisionIds)->delete();
        DB::table('observaciones_revision')->whereIn('id', $observationIds)->delete();
        DB::table('revisiones_silabo')->whereIn('id', $revisionIds)->delete();
        DB::table('notificaciones_internas')->whereIn('recurso_id', [...$syllabusIds, ...$revisionIds])->delete();

        Syllabus::query()->whereKey($syllabusIds)->delete();
    }

    /** @param  list<string>  $careerIds */
    public function refreshIdentification(array $careerIds): int
    {
        if ($careerIds === []) {
            return 0;
        }

        $syllabi = $this->inProgress($careerIds)->with('career.faculty')->lockForUpdate()->get();
        foreach ($syllabi as $syllabus) {
            $context = $syllabus->contexto_academico;
            $career = $syllabus->career;
            data_set($context, 'career.name', $career->nombre);
            data_set($context, 'career.code', $career->codigo_carrera);
            data_set($context, 'career.faculty', $career->faculty?->nombre);
            data_set($context, 'career.faculty_id', $career->facultad_id);

            $scheduled = ScheduledSubject::query()->find(data_get($context, 'scheduled_subject.id'));
            if ($scheduled instanceof ScheduledSubject) {
                data_set($context, 'scheduled_subject.campus', $scheduled->campus_programacion);
                data_set($context, 'scheduled_subject.modality', $scheduled->modalidad->label());
                $syllabus->values()->with('field')->get()
                    ->filter(fn (FieldValue $value): bool => $value->field->origen_maestro === 'asignaturas')
                    ->each(function (FieldValue $value) use ($scheduled): void {
                        $current = is_array($value->valor) ? $value->valor : [];
                        $value->update(['valor' => [
                            ...$current,
                            'campus' => $scheduled->campus_programacion,
                            'modalidad' => $scheduled->modalidad->label(),
                        ]]);
                    });
            }

            $syllabus->contexto_academico = $context;
            $syllabus->save();
        }

        return $syllabi->count();
    }

    public function refreshSubjects(string $careerId): int
    {
        $syllabi = $this->inProgress($careerId)->with(['career', 'parallel.scheduledSubject'])->lockForUpdate()->get();
        $subjectIdOf = fn (Syllabus $syllabus): string => $syllabus->parallel->scheduledSubject->asignatura_id;
        $subjects = Subject::query()
            ->whereKey($syllabi->map($subjectIdOf)->unique()->values()->all())
            ->with('requirements.requirement')
            ->get()
            ->keyBy('id');

        foreach ($syllabi as $syllabus) {
            $subject = $subjects->get($subjectIdOf($syllabus));
            if (! $subject instanceof Subject) {
                continue;
            }
            $codes = fn (string $type): array => $subject->requirements
                ->where('tipo', $type)
                ->map(fn (SubjectRequirement $requirement): string => $requirement->requirement->codigo_asignatura)
                ->sort()
                ->values()
                ->all();

            $context = $syllabus->contexto_academico;
            data_set($context, 'subject.name', $subject->nombre);
            data_set($context, 'subject.code', $subject->codigo_asignatura);
            data_set($context, 'subject.cycle', $subject->ciclo);
            data_set($context, 'subject.position', $subject->orden_en_ciclo);
            data_set($context, 'subject.organization_unit', $subject->unidad_organizacion_curricular?->label());
            data_set($context, 'subject.prerequisites', $codes('prerrequisito'));
            data_set($context, 'subject.corequisites', $codes('correquisito'));
            data_set($context, 'curriculum.cycle_count', $syllabus->career->cantidad_ciclos_malla);
            $syllabus->contexto_academico = $context;
            $syllabus->save();

            $syllabus->values()->with('field')->get()
                ->filter(fn (FieldValue $value): bool => $value->field->origen_maestro === 'asignaturas')
                ->each(function (FieldValue $value) use ($subject): void {
                    $current = is_array($value->valor) ? $value->valor : [];
                    $value->update(['valor' => [
                        ...$current,
                        'codigo' => $subject->codigo_asignatura,
                        'nombre' => $subject->nombre,
                        'ciclo' => $subject->ciclo,
                    ]]);
                });
        }

        return $syllabi->count();
    }

    /**
     * @param  string|list<string>|null  $careerIds
     * @param  list<string>|null  $subjectIds
     * @return Builder<Syllabus>
     */
    private function inProgress(string|array|null $careerIds, ?string $periodId = null, ?string $subjectId = null, ?array $subjectIds = null): Builder
    {
        return Syllabus::query()->where('estado', '!=', 'aprobado')
            ->whereDoesntHave('approvals')
            ->when($subjectId !== null, fn (Builder $query) => $query->forSubjects([$subjectId]))
            ->when($subjectIds !== null, fn (Builder $query) => $query->forSubjects($subjectIds))
            ->whereHas('convocation', fn (Builder $query) => $query
                ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
                ->whereHas('process', fn (Builder $process) => $process
                    ->whereIn('estado', [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED]))
                ->when(is_string($careerIds), fn (Builder $scoped) => $scoped->where('carrera_id', $careerIds))
                ->when(is_array($careerIds), fn (Builder $scoped) => $scoped->whereIn('carrera_id', $careerIds))
                ->when($periodId !== null, fn (Builder $scoped) => $scoped->whereHas(
                    'process',
                    fn (Builder $process) => $process->where('periodo_academico_id', $periodId),
                )));
    }
}
