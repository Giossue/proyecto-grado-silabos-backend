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

/**
 * Reinicia los expedientes cuya base institucional cambia durante una pausa.
 *
 * La confirmación, el cambio de la base, el borrado completo y la regeneración deben
 * vivir en la misma transacción exterior. Las revisiones siguen siendo inmutables en el
 * flujo normal; esta es la única excepción, habilitada localmente por la transacción.
 */
final class InProgressWork
{
    public const CONFIRMATION_FIELD = 'confirm_purge';

    public const DEFAULT_REASON = 'cambió la base institucional (malla, período académico o plantilla)';

    public function __construct(
        private readonly GenerateConvocationSyllabi $generator,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    /** @param string|list<string>|null $careerIds */
    public function count(string|array|null $careerIds = null, ?string $periodId = null): int
    {
        return $this->inProgress($careerIds, $periodId)->count();
    }

    /**
     * Devuelve cuántos expedientes deberán reemplazarse. No borra antes de que el
     * cambio institucional haya sido validado y guardado.
     *
     * @param  string|list<string>|null  $careerIds
     */
    public function requireConfirmation(Request $request, string|array|null $careerIds = null, ?string $periodId = null): int
    {
        $count = $this->count($careerIds, $periodId);
        if ($count === 0) {
            return 0;
        }

        if (! $request->boolean(self::CONFIRMATION_FIELD)) {
            throw ValidationException::withMessages([
                'purge_required' => $count === 1
                    ? 'Este cambio reemplazará 1 sílabo. Se eliminarán su borrador, revisiones, aprobaciones y análisis de IA, y se generará nuevamente desde la base corregida.'
                    : "Este cambio reemplazará {$count} sílabos. Se eliminarán sus borradores, revisiones, aprobaciones y análisis de IA, y se generarán nuevamente desde la base corregida.",
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
     * @return TResult
     */
    public function replace(
        Request $request,
        Closure $change,
        string|array|null $careerIds = null,
        ?string $periodId = null,
        ?string $reason = null,
    ): mixed {
        return DB::transaction(function () use ($careerIds, $change, $periodId, $reason, $request): mixed {
            $count = $this->requireConfirmation($request, $careerIds, $periodId);
            $result = $change();
            if ($count > 0) {
                $this->regenerate(
                    $careerIds,
                    $periodId,
                    $reason,
                    $request->attributes->getString('correlation_id') ?: null,
                );
            }

            return $result;
        });
    }

    /**
     * Elimina cada agregado afectado y lo vuelve a generar con la base vigente.
     * Debe llamarse después de guardar el cambio y dentro de esa misma transacción.
     * Cada docente vigente del expediente recibe un aviso de que perdió su contenido.
     *
     * @param  string|list<string>|null  $careerIds
     * @return array{deleted: int, generated: int}
     */
    public function regenerate(
        string|array|null $careerIds = null,
        ?string $periodId = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): array {
        $syllabi = $this->inProgress($careerIds, $periodId)->with('convocation:id,carrera_id')->lockForUpdate()
            ->get(['id', 'convocatoria_id', 'asignatura_id', 'contexto_academico']);
        if ($syllabi->isEmpty()) {
            return ['deleted' => 0, 'generated' => 0];
        }

        $convocationIds = $syllabi->pluck('convocatoria_id')->unique()->values()->all();
        $syllabusIds = array_values(array_map('strval', $syllabi->pluck('id')->all()));
        // Los destinatarios se resuelven antes del borrado: después el sílabo ya no existe.
        $teachers = $this->currentTeachers($syllabusIds);
        $this->deleteAggregates($syllabusIds);
        foreach ($syllabi as $syllabus) {
            $subject = $syllabus->academicSubjectName();
            // El aviso apunta a la carrera: el borrado del expediente no lo alcanza.
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
            $generated += $this->generator->execute($convocation);
        }

        return ['deleted' => count($syllabusIds), 'generated' => $generated];
    }

    /**
     * Docentes con responsabilidad activa sobre algún paralelo del expediente.
     *
     * @param  list<string>  $syllabusIds
     * @return array<string, list<string>>
     */
    private function currentTeachers(array $syllabusIds): array
    {
        // Misma regla que permisos y avisos: la vista de docentes vigentes del paralelo.
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
        // I-135: todo el historial de IA del sílabo cuelga de sesiones `silabo:{id}:…`.
        foreach ($syllabusIds as $syllabusId) {
            DB::table('historial_ia')->where('sesion_id', 'like', "silabo:{$syllabusId}:%")->delete();
        }

        $exports = DB::table('artefactos_exportacion')
            ->whereIn('revision_silabo_id', $revisionIds)
            ->get(['objeto_docx_id', 'objeto_pdf_id']);
        $objectIds = $exports->flatMap(fn ($export) => [$export->objeto_docx_id, $export->objeto_pdf_id])
            ->filter()->unique()->values()->all();
        $objects = DB::table('objetos_almacenados')->whereIn('id', $objectIds)->get(['disco', 'ruta_interna']);
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

    /**
     * Actualiza los datos de presentación de la ficha (facultad, carrera, campus y
     * modalidad) de los expedientes en curso sin tocar borradores, revisiones,
     * aprobaciones ni análisis de IA. Los logos se leen en vivo al exportar.
     *
     * @param  list<string>  $careerIds
     */
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
                $syllabus->values()->get()
                    ->filter(fn (FieldValue $value): bool => $value->origen === 'asignaturas')
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

    /**
     * Actualiza en los expedientes en curso de la carrera los datos de presentación de
     * la materia (nombre, código, ciclo, orden, unidad y requisitos por código) y la
     * cantidad de ciclos de la malla sin
     * reiniciar el trabajo docente. Se usa para cambios cosméticos de la malla.
     */
    public function refreshSubjects(string $careerId): int
    {
        $syllabi = $this->inProgress($careerId)->with('career')->lockForUpdate()->get();
        $subjects = Subject::query()
            ->whereKey($syllabi->pluck('asignatura_id')->unique()->values()->all())
            ->with('requirements.requirement')
            ->get()
            ->keyBy('id');

        foreach ($syllabi as $syllabus) {
            $subject = $subjects->get($syllabus->asignatura_id);
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

            $syllabus->values()->get()
                ->filter(fn (FieldValue $value): bool => $value->origen === 'asignaturas')
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

    /** @param string|list<string>|null $careerIds
     * @return Builder<Syllabus>
     */
    private function inProgress(string|array|null $careerIds, ?string $periodId = null): Builder
    {
        // Un sílabo aprobado queda fuera: ni se purga ni se refresca (igual que ResetSyllabus).
        return Syllabus::query()->where('estado', '!=', 'aprobado')->whereHas('convocation', fn (Builder $query) => $query
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
