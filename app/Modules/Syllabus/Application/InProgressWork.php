<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\ResponseKind;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InProgressWork
{
    public const CONFIRMATION_FIELD = 'confirm_purge';

    public const DEFAULT_REASON = 'cambió la base institucional (malla, período académico o plantilla)';

    public const BACK_TO_DRAFT_EVENT = 'silabo.devuelto_borrador';

    public const SENT_STATES = ['en_revision', 'correccion_solicitada', 'aprobado'];

    public const DOCUMENT_PATHS = [
        'career.name', 'career.faculty', 'career.faculty_logo', 'scheduled_subject.campus',
        'subject.name', 'subject.code', 'subject.cycle', 'subject.organization_unit', 'subject.credits',
        'subject.total_hours', 'subject.hours_ac', 'subject.hours_pae', 'subject.hours_aa',
        'subject.prerequisites', 'subject.corequisites', 'scheduled_subject.modality',
    ];

    // Se guardan en el sílabo pero no salen en el documento: cambiarlos no lo devuelve a borrador.
    private const SILENT_PATHS = ['subject.position', 'curriculum.cycle_count', 'career.code'];

    public function __construct(
        private readonly GenerateConvocationSyllabi $generator,
        private readonly ScheduleInternalNotification $notifications,
        private readonly AcademicContextSnapshot $academicContext,
        private readonly SubjectProfileFields $profiles,
        private readonly ScheduleWorkflowNotification $workflowNotifications,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly FinalizedChangeNotice $notice,
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
                    ? 'Este cambio reemplazará 1 sílabo. Se eliminará todo su contenido, incluso si ya estaba aprobado (borrador, revisiones, aprobación, firmas y análisis de IA), y se generará nuevamente desde la base corregida. El docente recibirá un aviso.'
                    : "Este cambio reemplazará {$count} sílabos. Se eliminará todo su contenido, incluso los ya aprobados (borradores, revisiones, aprobaciones, firmas y análisis de IA), y se generarán nuevamente desde la base corregida. Cada docente recibirá un aviso.",
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
                    $subjectId,
                    $subjectIds,
                );
            }

            return $result;
        });
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public function replaceTemplate(Closure $change): mixed
    {
        return DB::transaction($change);
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
        ?string $subjectId = null,
        ?array $subjectIds = null,
        bool $skipIncomplete = false,
    ): array {
        $syllabi = $this->inProgress($careerIds, $periodId, $subjectId, $subjectIds)->with('convocation:id,carrera_id')->lockForUpdate()
            ->get(['id', 'convocatoria_id', 'paralelo_id', 'contexto_academico']);
        if ($syllabi->isEmpty()) {
            return ['deleted' => 0, 'generated' => 0];
        }

        $convocationIds = $syllabi->pluck('convocatoria_id')->unique()->values()->all();
        $syllabusIds = array_values(array_map('strval', $syllabi->pluck('id')->all()));
        $teachers = $this->currentTeachers($syllabusIds);
        $periods = Convocation::query()->whereKey($convocationIds)->with('process')->get()
            ->mapWithKeys(fn (Convocation $convocation): array => [$convocation->id => $convocation->process->periodo_academico_id]);
        $this->deleteAggregates($syllabusIds);
        $rows = [];
        foreach ($syllabi as $syllabus) {
            foreach ($teachers[$syllabus->id] ?? [] as $teacherId) {
                $rows[] = [
                    'teacher' => $teacherId,
                    'career' => (string) $syllabus->convocation->carrera_id,
                    'period' => $periods[$syllabus->convocatoria_id] ?? null,
                    'subject' => $syllabus->academicSubjectName(),
                ];
            }
        }
        foreach (collect($rows)->groupBy(fn (array $row): string => "{$row['teacher']}|{$row['career']}|{$row['period']}") as $group) {
            $first = $group->first();
            $this->notifyRestarted(
                [$first['teacher']],
                array_values(array_unique(array_map('strval', $group->pluck('subject')->all()))),
                $group->count(),
                $reason ?? self::DEFAULT_REASON,
                'carrera',
                $first['career'],
                $first['period'],
            );
        }

        $generated = 0;
        $convocations = Convocation::query()
            ->whereKey($convocationIds)
            ->with('process')
            ->lockForUpdate()
            ->get();
        foreach ($convocations as $convocation) {
            $generated += $this->generator->execute($convocation, $subjectId, $subjectIds, allowEmpty: true, skipIncomplete: $skipIncomplete);
        }

        return ['deleted' => count($syllabusIds), 'generated' => $generated];
    }

    /**
     * Un solo aviso por docente aunque se reinicien varios de sus sílabos a la vez.
     *
     * @param  list<string>  $recipientIds
     * @param  list<string>  $subjects
     */
    private function notifyRestarted(array $recipientIds, array $subjects, int $count, string $reason, string $resourceType, string $resourceId, ?string $periodId): void
    {
        $names = count($subjects) > 1
            ? implode(', ', array_slice($subjects, 0, -1)).' y '.$subjects[array_key_last($subjects)]
            : ($subjects[0] ?? '');
        [$title, $which, $complete] = $count > 1
            ? ['Sus sílabos volvieron a empezar', "de los sílabos de {$names}", 'Complételos']
            : ['Su sílabo volvió a empezar', "del sílabo de {$names}", 'Complételo'];

        $this->notifications->execute(
            eventType: 'silabo.regenerado',
            deduplicationKey: "silabo.regenerado:{$resourceId}:".Str::uuid(),
            recipientIds: $recipientIds,
            title: $title,
            message: "Se perdió el contenido anterior {$which} (borrador, revisiones, aprobación, firmas y análisis de IA) porque {$reason}. {$complete} otra vez.",
            resourceType: $resourceType,
            resourceId: $resourceId,
            periodId: $periodId,
        );
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

    public function restartForParallel(string $parallelId): int
    {
        $syllabi = $this->inProgress(null)
            ->where('paralelo_id', $parallelId)
            ->with(['convocation.process', 'parallel'])
            ->lockForUpdate()
            ->get();
        if ($syllabi->isEmpty()) {
            return 0;
        }

        $this->deleteAggregates(array_values(array_map('strval', $syllabi->pluck('id')->all())));
        foreach ($syllabi as $syllabus) {
            $this->generator->generateForParallel($syllabus->convocation, $syllabus->parallel);
        }

        return $syllabi->count();
    }

    public function restartSyllabus(Syllabus $syllabus): Syllabus
    {
        $syllabus->loadMissing(['convocation.process', 'parallel']);
        $convocation = $syllabus->convocation;
        $parallel = $syllabus->parallel;
        $this->deleteAggregates([(string) $syllabus->id]);
        $this->generator->generateForParallel($convocation, $parallel);

        return Syllabus::query()
            ->where('convocatoria_carrera_id', $convocation->id)
            ->where('paralelo_id', $parallel->id)
            ->firstOrFail();
    }

    /**
     * Lleva un sílabo a la plantilla vigente sin perder lo escrito. Cada campo del docente conserva
     * la respuesta del campo con su misma clave si todavía encaja en él; si no encaja, solo ese
     * campo vuelve a quedar vacío. Los de Coordinación vuelven del proyecto de carrera y los campos
     * quitados se descartan. Un sílabo enviado o aprobado vuelve a borrador sin historial ni firmas,
     * para que se revise y firme otra vez con la plantilla nueva. Uno sin iniciar se genera de nuevo.
     */
    public function adaptToTemplate(Syllabus $syllabus): Syllabus
    {
        if ($syllabus->estado === 'sin_iniciar') {
            return $this->restartSyllabus($syllabus);
        }
        $syllabus->loadMissing('parallel');
        $stored = FieldValue::query()->where('silabo_id', $syllabus->id)->get();
        $keys = FieldDefinition::withoutGlobalScopes()->with('blockRecord')
            ->whereIn('definicion_campo_id', $stored->pluck('definicion_campo_id'))->get()
            ->mapWithKeys(fn (FieldDefinition $field): array => [(string) $field->id => $field->clave]);
        $previous = [];
        foreach ($stored as $value) {
            $key = $keys[(string) $value->definicion_campo_id] ?? '';
            if ($key !== '') {
                $previous[$key] = $value->valor;
            }
        }
        $templates = $this->profiles->templates((string) $syllabus->parallel->asignatura_id);

        if (in_array($syllabus->estado, self::SENT_STATES, true)) {
            $this->clearHistory([(string) $syllabus->id]);
            $syllabus->estado = 'borrador';
        }
        FieldValue::query()->where('silabo_id', $syllabus->id)->delete();
        foreach (FieldDefinition::query()->get() as $field) {
            if (! $field->career()) {
                continue;
            }
            $template = $templates[$field->id] ?? $field->contenido;
            $document = SubjectProfileFields::teacherEditable($field, $templates)
                ? $this->stillFits($field, $previous[$field->clave] ?? null, $template)
                : $template;
            FieldValue::query()->create([
                'silabo_id' => $syllabus->id,
                'definicion_campo_id' => $field->id,
                'valor' => $document === null || FieldDocument::isEmpty($document) ? $template : $document,
            ]);
        }
        $syllabus->version_bloqueo++;
        $syllabus->guardado_en = now()->toImmutable();
        $syllabus->save();

        return $syllabus;
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>|null
     */
    private function stillFits(FieldDefinition $field, mixed $value, array $template): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        try {
            $document = FieldResponse::normalize($template, $value);
        } catch (ValidationException) {
            return null;
        }

        return DocumentBlock::sameStructure(FieldDocument::normalize($field->contenido), $document) ? $document : null;
    }

    /**
     * @param  list<string>  $syllabusIds
     */
    public function purge(array $syllabusIds): void
    {
        if ($syllabusIds !== []) {
            $this->deleteAggregates($syllabusIds);
        }
    }

    /**
     * @param  list<string>  $syllabusIds
     */
    private function deleteAggregates(array $syllabusIds): void
    {
        $revisionIds = $this->clearHistory($syllabusIds);
        DB::table('notificaciones')->whereIn('recurso_id', [...$syllabusIds, ...$revisionIds])->delete();

        Syllabus::query()->whereKey($syllabusIds)->delete();
    }

    /**
     * @param  list<string>  $syllabusIds
     * @return list<string>
     */
    private function clearHistory(array $syllabusIds): array
    {
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        $revisionIds = array_values(array_map('strval', DB::table('revisiones_silabo')->whereIn('silabo_id', $syllabusIds)->pluck('revision_silabo_id')->all()));

        $exports = DB::table('exportaciones_silabo')
            ->whereIn('revision_silabo_id', $revisionIds)
            ->get(['exportacion_silabo_id', 'archivo_pdf_id']);
        $objectIds = $exports->pluck('archivo_pdf_id')
            ->filter()->unique()->values()->all();
        $objects = DB::table('archivos')->whereIn('archivo_id', $objectIds)->get(['disco', 'ruta_archivo']);
        DB::table('firmas_silabo')->whereIn('silabo_id', $syllabusIds)->delete();
        DB::table('exportaciones_silabo')->whereIn('revision_silabo_id', $revisionIds)->delete();
        DB::table('archivos')->whereIn('archivo_id', $objectIds)->delete();
        DB::afterCommit(function () use ($objects): void {
            foreach ($objects as $object) {
                Storage::disk($object->disco)->delete($object->ruta_archivo);
            }
        });

        DB::table('envios_biblioteca')->whereIn('revision_silabo_id', $revisionIds)->delete();
        DB::table('revisiones_coordinacion')->whereIn('revision_silabo_id', $revisionIds)->delete();
        DB::table('aprobaciones')->whereIn('revision_silabo_id', $revisionIds)->delete();
        // De la última a la primera: cada revisión apunta a la anterior y la base no deja una
        // revisión posterior sin anterior.
        $ordered = DB::table('revisiones_silabo')->whereIn('revision_silabo_id', $revisionIds)
            ->orderByDesc('numero_revision')->pluck('revision_silabo_id');
        foreach ($ordered as $revisionId) {
            DB::table('revisiones_silabo')->where('revision_silabo_id', $revisionId)->delete();
        }

        return $revisionIds;
    }

    public function refreshParallel(string $parallelId): int
    {
        $syllabi = $this->inProgress(null)->where('paralelo_id', $parallelId)->with('career.faculty')->lockForUpdate()->get();
        foreach ($syllabi as $syllabus) {
            $this->applyIdentification($syllabus);
        }

        return $syllabi->count();
    }

    public function restartSentForParallel(string $parallelId): int
    {
        $syllabi = $this->inProgress(null)->where('paralelo_id', $parallelId)->whereIn('estado', ['en_revision', 'aprobado'])
            ->with(['convocation.process', 'parallel'])->lockForUpdate()->get();
        $teachers = $this->currentTeachers(array_values(array_map('strval', $syllabi->pluck('id')->all())));
        foreach ($syllabi as $syllabus) {
            $subject = $syllabus->academicSubjectName();
            $periodId = $syllabus->convocation->process->periodo_academico_id;
            $fresh = $this->restartSyllabus($syllabus);
            $this->notifyRestarted(
                $teachers[$syllabus->id] ?? [],
                [$subject],
                1,
                'Coordinación cambió el código o la jornada del paralelo',
                'silabo',
                (string) $fresh->id,
                $periodId,
            );
        }

        return $syllabi->count();
    }

    public function parallelHasSentSyllabus(string $parallelId): bool
    {
        return $this->parallelsWithSentSyllabus([$parallelId]) !== [];
    }

    /**
     * Paralelos cuyo sílabo en curso ya tiene trabajo del docente (abierto, en borrador, enviado o aprobado):
     * cambiar su docente lo elimina y el nuevo empieza de cero, así que se pide confirmación.
     *
     * @param  array<int, string>  $parallelIds
     * @return list<string>
     */
    public function parallelsWithStartedSyllabus(array $parallelIds): array
    {
        if ($parallelIds === []) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (mixed $id): string => is_string($id) ? $id : '',
            $this->inProgress(null)->whereIn('paralelo_id', $parallelIds)
                ->where(fn (Builder $query) => $query->where('estado', '!=', 'sin_iniciar')->orWhereNotNull('iniciado_en'))
                ->pluck('paralelo_id')->all(),
        )));
    }

    /**
     * Paralelos cuyo sílabo en curso ya se envió o aprobó: cambiar su código o jornada lo reinicia (pide confirmación).
     * La pantalla los marca para preguntar una sola vez antes de un cambio masivo.
     *
     * @param  array<int, string>  $parallelIds
     * @return list<string>
     */
    public function parallelsWithSentSyllabus(array $parallelIds): array
    {
        if ($parallelIds === []) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (mixed $id): string => is_string($id) ? $id : '',
            $this->inProgress(null)->whereIn('paralelo_id', $parallelIds)->whereIn('estado', ['en_revision', 'aprobado'])->pluck('paralelo_id')->all(),
        )));
    }

    private function applyIdentification(Syllabus $syllabus): void
    {
        $context = $syllabus->contexto_academico;
        $career = $syllabus->career;
        data_set($context, 'career.name', $career->nombre);
        data_set($context, 'career.code', $career->codigo_carrera);
        data_set($context, 'career.faculty', $career->faculty?->nombre);
        data_set($context, 'career.faculty_id', $career->facultad_id);

        $scheduled = $syllabus->parallel;
        data_set($context, 'parallel.id', $scheduled->id);
        data_set($context, 'parallel.code', $scheduled->codigo);
        data_set($context, 'parallel.shift', $scheduled->jornada);
        data_set($context, 'scheduled_subject.campus', $scheduled->campus_programacion);
        data_set($context, 'scheduled_subject.modality', $scheduled->modalidad->label());
        $syllabus->contexto_academico = $context;
        $syllabus->save();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @param  list<string>|null  $subjectIds
     * @return TResult
     */
    public function keepContent(Request $request, string $careerId, Closure $change, ?array $subjectIds = null): mixed
    {
        return DB::transaction(function () use ($careerId, $change, $request, $subjectIds): mixed {
            $subjects = array_values(array_unique(array_map(
                'strval',
                $this->inProgress($careerId, null, null, $subjectIds)->join('paralelos', 'paralelos.paralelo_id', '=', 'silabos.paralelo_id')
                    ->pluck('paralelos.asignatura_id')->all(),
            )));
            $before = $this->profilesFor($subjects);
            $result = $this->notice->guard($request, $careerId, $change);
            $this->sync($request, $careerId, $subjectIds, $before);

            return $result;
        });
    }

    /**
     * @param  string|list<string>  $careerId
     * @param  list<string>|null  $subjectIds
     * @param  array<string, array<string, array<string, mixed>|null>>  $profilesBefore
     */
    public function sync(Request $request, string|array $careerId, ?array $subjectIds = null, array $profilesBefore = []): int
    {
        $syllabi = $this->inProgress($careerId, null, null, $subjectIds)
            ->with('convocation.process.academicPeriod')
            ->lockForUpdate()
            ->get();
        $fields = $profilesBefore === [] ? [] : $this->profiles->fields()->keyBy('id')->all();
        $profilesAfter = $this->profilesFor(array_map('strval', array_keys($profilesBefore)));
        $pending = $this->pendingChanges($syllabi, function (Parallel $parallel, Syllabus $syllabus) use ($fields, $profilesAfter, $profilesBefore): array {
            $documents = [];
            foreach ($fields as $fieldId => $field) {
                $before = $profilesBefore[$parallel->asignatura_id][$fieldId] ?? null;
                $after = $profilesAfter[$parallel->asignatura_id][$fieldId] ?? null;
                if ($before == $after) {
                    continue;
                }
                $template = SubjectProfileFields::templateOf($field, $after);
                $documents += $template === null || FieldResponse::open($template)
                    ? $this->rebuilt($syllabus, $field, $template ?? $field->contenido)
                    : [(string) $fieldId => $template];
            }

            return $documents;
        });

        $count = count(array_filter($pending, fn (array $item): bool => $item['back_to_draft']));
        if ($count > 0 && ! $request->boolean(self::CONFIRMATION_FIELD)) {
            throw ValidationException::withMessages([
                'purge_required' => $count === 1
                    ? 'Este cambio devolverá 1 sílabo enviado o aprobado a borrador. Se conserva lo escrito; el docente deberá enviarlo y firmarlo de nuevo, y recibirá un aviso por correo.'
                    : "Este cambio devolverá {$count} sílabos enviados o aprobados a borrador. Se conserva lo escrito; cada docente deberá enviarlo y firmarlo de nuevo, y recibirá un aviso por correo.",
                'purge_count' => (string) $count,
            ]);
        }
        $this->applyChanges($pending);

        return $count;
    }

    /**
     * @param  iterable<Syllabus>  $syllabi
     * @return list<string>
     */
    public function returningToDraft(iterable $syllabi): array
    {
        $pending = $this->pendingChanges($syllabi, $this->heldDocuments(), onlyStoredPaths: true);

        return array_values(array_map(
            fn (array $item): string => (string) $item['syllabus']->id,
            array_filter($pending, fn (array $item): bool => $item['back_to_draft']),
        ));
    }

    /** @param  list<string>  $careerIds */
    public function catchUp(array $careerIds): void
    {
        $syllabi = $this->inProgress($careerIds)->with('convocation.process.academicPeriod')->orderBy('id')->lockForUpdate()->get();
        $this->applyChanges($this->pendingChanges($syllabi, $this->heldDocuments(), onlyStoredPaths: true));
    }

    /**
     * @param  iterable<Syllabus>  $syllabi
     * @param  Closure(Parallel, Syllabus): array<string, array<string, mixed>>  $documentsFor
     * @return list<array{syllabus: Syllabus, context: array<string, mixed>, documents: array<string, array<string, mixed>>, back_to_draft: bool}>
     */
    private function pendingChanges(iterable $syllabi, Closure $documentsFor, bool $onlyStoredPaths = false): array
    {
        $pending = [];
        foreach ($syllabi as $syllabus) {
            $parallel = Parallel::query()->find($syllabus->paralelo_id);
            if (! $parallel instanceof Parallel) {
                continue;
            }
            $context = $this->academicContext->build($parallel, $syllabus->convocation->process->academicPeriod);
            $updated = $syllabus->contexto_academico;
            // Los sílabos creados antes de guardar la huella del logo no cuentan como cambiados por no tenerla.
            if (! array_key_exists('faculty_logo', (array) data_get($updated, 'career', []))) {
                data_set($updated, 'career.faculty_logo', data_get($context, 'career.faculty_logo'));
            }
            $visible = false;
            $silent = false;
            foreach ([...self::DOCUMENT_PATHS, ...self::SILENT_PATHS] as $path) {
                $value = data_get($context, $path);
                if (data_get($updated, $path) != $value) {
                    in_array($path, self::DOCUMENT_PATHS, true) && (! $onlyStoredPaths || Arr::has($updated, $path)) ? $visible = true : $silent = true;
                    data_set($updated, $path, $value);
                }
            }
            $documents = $documentsFor($parallel, $syllabus);
            if (! $visible && ! $silent && $documents === []) {
                continue;
            }
            $pending[] = [
                'syllabus' => $syllabus,
                'context' => $updated,
                'documents' => $documents,
                'back_to_draft' => ($visible || $documents !== []) && in_array($syllabus->estado, self::SENT_STATES, true),
            ];
        }

        return $pending;
    }

    /** @return Closure(Parallel, Syllabus): array<string, array<string, mixed>> */
    private function heldDocuments(): Closure
    {
        $fields = $this->profiles->fields();
        $templates = [];

        return function (Parallel $parallel, Syllabus $syllabus) use ($fields, &$templates): array {
            $built = $templates[$parallel->asignatura_id] ??= $this->profiles->templates((string) $parallel->asignatura_id);
            $held = FieldValue::query()->where('silabo_id', $syllabus->id)->get()->keyBy('definicion_campo_id');
            $documents = [];
            foreach ($fields as $field) {
                $value = $held->get($field->id);
                if (! $value instanceof FieldValue) {
                    continue;
                }
                $template = $built[$field->id] ?? $field->contenido;
                if (! SubjectProfileFields::teacherEditable($field, $built)) {
                    if (ResponseKind::withoutOwnOptions($value->valor) != $template
                        && (ResponseKind::withoutOwnOptions(FieldResponse::templateFor($field->contenido, $value->valor)) != $template || ResponseKind::optionsOf($value->valor) != ResponseKind::optionsOf($template))) {
                        $documents[(string) $field->id] = $template;
                    }

                    continue;
                }
                if (isset($built[$field->id]) || ResponseKind::within($value->valor)) {
                    $documents += $this->rebuilt($syllabus, $field, $template, $value);
                }
            }

            return $documents;
        };
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, array<string, mixed>>
     */
    private function rebuilt(Syllabus $syllabus, FieldDefinition $field, array $template, ?FieldValue $held = null): array
    {
        $held ??= FieldValue::query()->where('silabo_id', $syllabus->id)->where('definicion_campo_id', $field->id)->first();
        if (! $held instanceof FieldValue || FieldResponse::fits($template, $held->valor)) {
            return [];
        }

        return [(string) $field->id => FieldResponse::carried($template, $held->valor)];
    }

    /** @param  list<array{syllabus: Syllabus, context: array<string, mixed>, documents: array<string, array<string, mixed>>, back_to_draft: bool}>  $pending */
    private function applyChanges(array $pending): void
    {
        $returning = array_values(array_filter($pending, fn (array $item): bool => $item['back_to_draft']));
        if ($returning !== []) {
            $this->clearHistory(array_map(fn (array $item): string => (string) $item['syllabus']->id, $returning));
        }

        foreach ($pending as $item) {
            $syllabus = $item['syllabus'];
            $syllabus->contexto_academico = $item['context'];
            foreach ($item['documents'] as $fieldId => $document) {
                FieldValue::query()->updateOrCreate(
                    ['silabo_id' => $syllabus->id, 'definicion_campo_id' => $fieldId],
                    ['valor' => $document],
                );
            }
            if ($item['back_to_draft']) {
                $syllabus->estado = 'borrador';
                $syllabus->version_bloqueo++;
                $syllabus->guardado_en = now()->toImmutable();
            }
            $syllabus->save();
            if ($item['back_to_draft']) {
                $this->workflowNotifications->execute(
                    syllabus: $syllabus,
                    eventType: self::BACK_TO_DRAFT_EVENT,
                    deduplicationKey: self::BACK_TO_DRAFT_EVENT.':'.$syllabus->id.':'.Str::uuid(),
                    recipientIds: $this->recipients->teachersFor($syllabus),
                    revisionNumber: null,
                );
            }
        }
    }

    /**
     * @param  list<string>  $subjectIds
     * @return array<string, array<string, array<string, mixed>|null>>
     */
    private function profilesFor(array $subjectIds): array
    {
        $profiles = [];
        foreach ($subjectIds as $subjectId) {
            $profiles[$subjectId] = $this->profiles->values($subjectId);
        }

        return $profiles;
    }

    /**
     * @param  string|list<string>|null  $careerIds
     * @param  list<string>|null  $subjectIds
     * @return Builder<Syllabus>
     */
    private function inProgress(string|array|null $careerIds, ?string $periodId = null, ?string $subjectId = null, ?array $subjectIds = null): Builder
    {
        return Syllabus::query()
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
