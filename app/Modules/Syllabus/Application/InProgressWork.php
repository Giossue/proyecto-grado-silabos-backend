<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function __construct(private readonly GenerateConvocationSyllabi $generator) {}

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
    ): mixed {
        return DB::transaction(function () use ($careerIds, $change, $periodId, $request): mixed {
            $count = $this->requireConfirmation($request, $careerIds, $periodId);
            $result = $change();
            if ($count > 0) {
                $this->regenerate($careerIds, $periodId);
            }

            return $result;
        });
    }

    /**
     * Elimina cada agregado afectado y lo vuelve a generar con la base vigente.
     * Debe llamarse después de guardar el cambio y dentro de esa misma transacción.
     *
     * @param  string|list<string>|null  $careerIds
     * @return array{deleted: int, generated: int}
     */
    public function regenerate(string|array|null $careerIds = null, ?string $periodId = null): array
    {
        $syllabi = $this->inProgress($careerIds, $periodId)->lockForUpdate()->get(['id', 'convocatoria_id']);
        if ($syllabi->isEmpty()) {
            return ['deleted' => 0, 'generated' => 0];
        }

        $convocationIds = $syllabi->pluck('convocatoria_id')->unique()->values()->all();
        $syllabusIds = array_values(array_map('strval', $syllabi->pluck('id')->all()));
        $this->deleteAggregates($syllabusIds);

        $generated = 0;
        $convocations = Convocation::query()
            ->whereKey($convocationIds)
            ->with('process.template')
            ->lockForUpdate()
            ->get();
        foreach ($convocations as $convocation) {
            $generated += $this->generator->execute($convocation);
        }

        return ['deleted' => count($syllabusIds), 'generated' => $generated];
    }

    /** @param list<string> $syllabusIds */
    private function deleteAggregates(array $syllabusIds): void
    {
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        $revisionIds = DB::table('revisiones_silabo')->whereIn('silabo_id', $syllabusIds)->pluck('id')->all();
        $conversationIds = DB::table('conversaciones_ia')->whereIn('silabo_id', $syllabusIds)->pluck('id')->all();
        $messageIds = DB::table('mensajes_ia')->whereIn('conversacion_ia_id', $conversationIds)->pluck('id')->all();
        $executionIds = DB::table('ejecuciones_ia')->whereIn('silabo_id', $syllabusIds)->pluck('id')->all();
        $recommendationIds = DB::table('recomendaciones_ia')->whereIn('ejecucion_ia_id', $executionIds)->pluck('id')->all();
        $evidenceIds = DB::table('evidencias_ia')->whereIn('ejecucion_ia_id', $executionIds)->pluck('id')->all();

        $messageEvidenceIds = DB::table('evidencias_mensaje_ia')->whereIn('mensaje_ia_id', $messageIds)->pluck('id')->all();
        DB::table('citas_mensaje_ia')->whereIn('mensaje_ia_id', $messageIds)
            ->orWhereIn('evidencia_mensaje_ia_id', $messageEvidenceIds)->delete();
        DB::table('evidencias_mensaje_ia')->whereIn('mensaje_ia_id', $messageIds)->delete();
        DB::table('mensajes_ia')->whereIn('id', $messageIds)->update(['respuesta_a_id' => null]);
        DB::table('mensajes_ia')->whereIn('id', $messageIds)->delete();
        DB::table('conversaciones_ia')->whereIn('id', $conversationIds)->delete();

        DB::table('retroalimentacion_ia')->whereIn('recomendacion_ia_id', $recommendationIds)->delete();
        DB::table('recomendacion_evidencias_ia')->whereIn('recomendacion_ia_id', $recommendationIds)
            ->orWhereIn('evidencia_ia_id', $evidenceIds)->delete();
        DB::table('recomendaciones_ia')->whereIn('id', $recommendationIds)->delete();
        DB::table('evidencias_ia')->whereIn('id', $evidenceIds)->delete();
        DB::table('ejecuciones_ia')->whereIn('id', $executionIds)->delete();
        DB::table('revisiones_ia_silabo')->whereIn('silabo_id', $syllabusIds)->delete();

        $exports = DB::table('artefactos_exportacion')
            ->whereIn('silabo_id', $syllabusIds)
            ->get(['objeto_docx_id', 'objeto_pdf_id']);
        $objectIds = $exports->flatMap(fn ($export) => [$export->objeto_docx_id, $export->objeto_pdf_id])
            ->filter()->unique()->values()->all();
        $objects = DB::table('objetos_almacenados')->whereIn('id', $objectIds)->get(['disco', 'ruta_interna']);
        DB::table('artefactos_exportacion')->whereIn('silabo_id', $syllabusIds)->delete();
        DB::table('objetos_almacenados')->whereIn('id', $objectIds)->delete();
        DB::afterCommit(function () use ($objects): void {
            foreach ($objects as $object) {
                Storage::disk($object->disco)->delete($object->ruta_interna);
            }
        });

        $observationIds = DB::table('observaciones_revision')->whereIn('revision_silabo_id', $revisionIds)->pluck('id')->all();
        $correctionIds = DB::table('solicitudes_correccion')->whereIn('silabo_id', $syllabusIds)->pluck('id')->all();
        DB::table('solicitud_correccion_observaciones')->whereIn('solicitud_correccion_id', $correctionIds)
            ->orWhereIn('observacion_revision_id', $observationIds)->delete();
        DB::table('revisiones_silabo')->whereIn('id', $revisionIds)->update([
            'revision_anterior_id' => null,
            'reapertura_id' => null,
        ]);
        DB::table('reaperturas')->whereIn('silabo_id', $syllabusIds)->delete();
        DB::table('aprobaciones')->whereIn('silabo_id', $syllabusIds)->delete();
        DB::table('solicitudes_correccion')->whereIn('silabo_id', $syllabusIds)->delete();
        DB::table('observaciones_revision')->whereIn('id', $observationIds)->delete();
        DB::table('transiciones_silabo')->whereIn('silabo_id', $syllabusIds)->delete();
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

    /** @param string|list<string>|null $careerIds
     * @return Builder<Syllabus>
     */
    private function inProgress(string|array|null $careerIds, ?string $periodId = null): Builder
    {
        return Syllabus::query()->whereHas('convocation', fn (Builder $query) => $query
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
