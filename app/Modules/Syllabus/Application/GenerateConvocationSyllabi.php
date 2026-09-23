<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusCollaborator;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusScope;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Genera desde las fuentes maestras vigentes todos los sílabos de una convocatoria. */
final class GenerateConvocationSyllabi
{
    public function __construct(
        private readonly AcademicContextSnapshot $academicContext,
        private readonly InheritMasterValues $inherit,
    ) {}

    public function execute(Convocation $convocation): int
    {
        $convocation->loadMissing('process.template');
        $scheduledSubjects = ScheduledSubject::query()
            ->where('periodo_academico_id', $convocation->process->periodo_academico_id)
            ->where('activo', true)
            ->whereHas('subject', fn ($query) => $query->where('carrera_id', $convocation->carrera_id))
            ->with([
                'subject.career.faculty',
                'parallels' => fn ($query) => $query->lockForUpdate()->with([
                    'teacherAssignments' => fn ($assignmentQuery) => $assignmentQuery
                        ->where('asignaciones_paralelo.docente_paralelo_activo', true)
                        ->whereHas('roleAssignment', fn ($roleQuery) => $roleQuery
                            ->where('asignaciones_rol.asignacion_rol_activa', true)
                            ->where('asignaciones_rol.rol', RoleCode::Teacher->value)
                            ->where('asignaciones_rol.carrera_id', $convocation->carrera_id)
                            ->whereHas('user', fn ($userQuery) => $userQuery
                                ->where('usuarios.usuario_activo', true)
                                ->whereNotNull('usuarios.correo_verificado_en')))
                        ->with('roleAssignment')
                        ->lockForUpdate(),
                ]),
            ])
            ->orderBy('asignatura_id')
            ->lockForUpdate()
            ->get();

        if ($scheduledSubjects->isEmpty()) {
            throw ValidationException::withMessages([
                'convocation' => 'No existen materias programadas activas de la carrera para el período.',
            ]);
        }

        foreach ($scheduledSubjects as $scheduledSubject) {
            if ($scheduledSubject->parallels->isEmpty() || $scheduledSubject->parallels->contains(
                fn (Parallel $parallel): bool => $parallel->teacherAssignments->count() !== 1,
            )) {
                throw ValidationException::withMessages([
                    'convocation' => "La materia {$scheduledSubject->subject->nombre} debe tener exactamente un docente vigente por paralelo.",
                ]);
            }
        }

        $generated = 0;
        foreach ($scheduledSubjects as $scheduledSubject) {
            foreach ($scheduledSubject->parallels as $parallel) {
                $this->generate($convocation, $scheduledSubject, new Collection([$parallel]));
                $generated++;
            }
        }

        return $generated;
    }

    /** @param Collection<int, Parallel> $parallels */
    private function generate(Convocation $convocation, ScheduledSubject $scheduledSubject, Collection $parallels): void
    {
        $syllabus = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'asignatura_id' => $scheduledSubject->subject->id,
            'carrera_id' => $scheduledSubject->subject->carrera_id,
            'plantilla_id' => $convocation->process->plantilla_id,
            'contexto_academico' => $this->academicContext->build($scheduledSubject),
            'estado' => 'sin_iniciar',
        ]);

        foreach ($parallels as $parallel) {
            SyllabusScope::query()->create([
                'silabo_id' => $syllabus->id,
                'convocatoria_id' => $convocation->id,
                'programacion_asignatura_id' => $scheduledSubject->id,
                'paralelo_id' => $parallel->id,
            ]);
            foreach ($parallel->teacherAssignments as $assignment) {
                $this->addCollaborator($syllabus, $assignment);
            }
        }

        $fields = $convocation->process->template->sections
            ->flatMap(fn ($section) => $section->blocks->flatMap(fn ($block) => $block->fields));
        $this->inherit->execute($syllabus, $fields, $scheduledSubject);
    }

    private function addCollaborator(Syllabus $syllabus, TeacherAssignment $assignment): void
    {
        SyllabusCollaborator::query()->firstOrCreate([
            'silabo_id' => $syllabus->id,
            'docente_paralelo_id' => $assignment->id,
        ], ['usuario_id' => $assignment->roleAssignment->usuario_id]);
    }
}
