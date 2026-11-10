<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;
use App\Modules\Academic\Infrastructure\Persistence\Models\SupportDocument;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Support\Collection;

/**
 * Qué registro tocó cada evento de auditoría, en palabras: «Programación Básica · Paralelo A · ANA PÉREZ».
 * Se resuelve por lotes (una consulta por tipo) y, si el registro ya no existe, queda vacío.
 */
final class AuditTargets
{
    /** Eventos de uso diario que no cambian datos: se ocultan salvo que se pida la actividad técnica. */
    public const TECHNICAL_ACTIONS = [
        'sesion.iniciada', 'sesion.cerrada', 'sesiones.remotas_cerradas', 'rol_activo.seleccionado',
        'silabo.campo_guardado', 'silabo.validado', 'silabo.borrador_iniciado',
        'documento.descargado', 'documento.exportacion_solicitada', 'documento.exportacion_completada', 'documento.exportacion_fallida',
        'trabajo.reintento_solicitado', 'ia.plantilla_solicitada', 'ia.analisis_solicitado', 'ia.analisis_completado', 'ia.analisis_fallido',
        'plantilla.propuesta_ia_descartada', 'ayuda.guardada', 'ayuda.eliminada', 'ia.chat_solicitado',
    ];

    /** Tipos de recurso que apuntan a un sílabo a través de su revisión. */
    private const REVISION_BACKED = ['aprobacion', 'revision_coordinacion', 'envio_biblioteca', 'firma_silabo', 'revision_silabo'];

    /**
     * @param  iterable<AuditEvent>  $events
     * @return array<string, string> id del evento => descripción del registro
     */
    public function describe(iterable $events): array
    {
        $byType = [];
        foreach ($events as $event) {
            if (is_string($event->recurso_id) && $event->recurso_id !== '') {
                $byType[$event->tipo_recurso][] = $event->recurso_id;
            }
        }
        $names = [];
        foreach ($byType as $type => $ids) {
            $names[$type] = $this->namesFor($type, array_values(array_unique($ids)));
        }

        $described = [];
        foreach ($events as $event) {
            $described[$event->id] = is_string($event->recurso_id) ? ($names[$event->tipo_recurso][$event->recurso_id] ?? '') : '';
        }

        return $described;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function namesFor(string $type, array $ids): array
    {
        if (in_array($type, self::REVISION_BACKED, true)) {
            return $this->syllabusNames($this->revisionIds($type, $ids));
        }

        return match ($type) {
            'silabo' => $this->syllabusNames(array_combine($ids, $ids)),
            'paralelo' => Parallel::query()->with('subject')->whereKey($ids)->get()
                ->mapWithKeys(fn (Parallel $parallel): array => [$parallel->id => "{$parallel->subject->codigo_asignatura} {$parallel->subject->nombre} · Paralelo {$parallel->codigo}"])->all(),
            'asignatura' => Subject::query()->whereKey($ids)->get()
                ->mapWithKeys(fn (Subject $subject): array => [$subject->getKey() => "{$subject->codigo_asignatura} {$subject->nombre}"])->all(),
            'usuario' => User::query()->whereKey($ids)->get()
                ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->nombre} ({$user->correo_electronico})"])->all(),
            'asignacion_rol' => RoleAssignment::query()->with(['user', 'career'])->whereKey($ids)->get()
                ->mapWithKeys(fn (RoleAssignment $assignment): array => [$assignment->id => trim("{$assignment->user?->nombre} · {$assignment->role->nombre_rol}".($assignment->career ? " de {$assignment->career->label()}" : ''))])->all(),
            'asignacion_docente' => TeacherAssignment::query()->with(['roleAssignment.user', 'parallel.subject'])->whereKey($ids)->get()
                ->mapWithKeys(fn (TeacherAssignment $assignment): array => [$assignment->id => "{$assignment->parallel->subject->nombre} · Paralelo {$assignment->parallel->codigo} · {$assignment->roleAssignment->user?->nombre}"])->all(),
            'facultad' => Faculty::query()->whereKey($ids)->get()->mapWithKeys(fn (Faculty $faculty): array => [$faculty->id => $faculty->nombre])->all(),
            'carrera' => Career::query()->whereKey($ids)->get()->mapWithKeys(fn (Career $career): array => [$career->id => $career->label()])->all(),
            'periodo' => AcademicPeriod::query()->whereKey($ids)->get()->mapWithKeys(fn (AcademicPeriod $period): array => [$period->id => $period->codigo])->all(),
            'convocatoria' => Convocation::query()->with(['career', 'process.academicPeriod'])->whereKey($ids)->get()
                ->mapWithKeys(fn (Convocation $convocation): array => [$convocation->id => "{$convocation->career->label()} · {$convocation->process->academicPeriod?->codigo}"])->all(),
            'proceso_silabos' => SyllabusProcess::query()->with('academicPeriod')->whereKey($ids)->get()
                ->mapWithKeys(fn (SyllabusProcess $process): array => [$process->id => "Convocatoria institucional {$process->academicPeriod?->codigo}"])->all(),
            'documento_apoyo' => SupportDocument::query()->whereKey($ids)->get()
                ->mapWithKeys(fn (SupportDocument $document): array => [$document->documento_apoyo_id => $document->titulo_documento])->all(),
            'requisito_asignatura' => SubjectRequirement::query()->with(['subject', 'requirement'])->whereKey($ids)->get()
                ->mapWithKeys(fn (SubjectRequirement $requirement): array => [$requirement->id => "{$requirement->subject?->codigo_asignatura} requiere {$requirement->requirement?->codigo_asignatura}"])->all(),
            'plantilla_silabo' => array_fill_keys($ids, 'Plantilla del sílabo'),
            default => [],
        };
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string> id del recurso => id del sílabo
     */
    private function revisionIds(string $type, array $ids): array
    {
        /** @var Collection<string, string> $revisionByResource */
        $revisionByResource = match ($type) {
            'revision_silabo' => collect(array_combine($ids, $ids)),
            'aprobacion' => Approval::query()->whereKey($ids)->pluck('revision_silabo_id', 'aprobacion_id'),
            'revision_coordinacion' => CoordinationReview::query()->whereKey($ids)->pluck('revision_silabo_id', 'revision_coordinacion_id'),
            'envio_biblioteca' => LibrarySubmission::query()->whereKey($ids)->pluck('revision_silabo_id', 'envio_biblioteca_id'),
            'firma_silabo' => SyllabusSignature::query()->whereKey($ids)->pluck('revision_silabo_id', (new SyllabusSignature)->getKeyName()),
            default => collect(),
        };
        $syllabusByRevision = SyllabusRevision::query()->whereKey($revisionByResource->values()->unique()->all())->pluck('silabo_id', 'revision_silabo_id');

        $map = [];
        foreach ($revisionByResource as $resourceId => $revisionId) {
            $syllabusId = $syllabusByRevision[$revisionId] ?? null;
            if (is_string($syllabusId)) {
                $map[(string) $resourceId] = $syllabusId;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $syllabusByResource
     * @return array<string, string>
     */
    private function syllabusNames(array $syllabusByResource): array
    {
        $syllabi = Syllabus::query()->with(['parallel', 'teachers:id,nombre'])->whereKey(array_values(array_unique($syllabusByResource)))->get()->keyBy('id');
        $names = [];
        foreach ($syllabusByResource as $resourceId => $syllabusId) {
            $syllabus = $syllabi->get($syllabusId);
            if ($syllabus instanceof Syllabus) {
                $teachers = $syllabus->teachers->pluck('nombre')->unique()->implode(', ');
                $names[$resourceId] = "{$syllabus->academicSubjectName()} · Paralelo {$syllabus->academicParallelCode()}".($teachers !== '' ? " · {$teachers}" : '');
            }
        }

        return $names;
    }
}
