<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;

class AcademicContextSnapshot
{
    /** @return array<string, mixed> */
    public function build(Parallel $parallel, AcademicPeriod $period): array
    {
        $parallel->loadMissing('subject');

        return [
            ...$this->forSubject($parallel->subject),
            'parallel' => [
                'id' => $parallel->id,
                'code' => $parallel->codigo,
                'shift' => $parallel->jornada,
            ],
            'scheduled_subject' => [
                'period' => $period->codigo,
                'teaching_weeks' => $period->semanas_lectivas,
                'campus' => $parallel->campus_programacion,
                'modality' => $parallel->modalidad->label(),
            ],
        ];
    }

    /**
     * Lo que depende solo de la materia y su carrera (sin paralelo ni período): sirve también para la vista previa del proyecto de carrera.
     *
     * @return array<string, mixed>
     */
    public function forSubject(Subject $subject): array
    {
        $subject->loadMissing([
            'career.faculty.logoObject',
            'requirements.requirement',
        ]);
        $career = $subject->career;
        $requirementCodes = fn (string $type): array => $subject->requirements
            ->where('tipo', $type)
            ->map(fn (SubjectRequirement $requirement): string => $requirement->requirement->codigo_asignatura)
            ->sort()
            ->values()
            ->all();

        return [
            'schema_version' => 6,
            'career' => [
                'id' => $career->id,
                'code' => $career->codigo_carrera,
                'name' => $career->nombre,
                'faculty' => $career->faculty?->nombre,
                'faculty_id' => $career->facultad_id,
                'faculty_logo' => $career->faculty?->logoObject?->huella_sha256,
            ],
            'curriculum' => [
                'id' => $career->id,
                'cycle_count' => $career->cantidad_ciclos_malla,
            ],
            'subject' => [
                'id' => $subject->id,
                'code' => $subject->codigo_asignatura,
                'name' => $subject->nombre,
                'cycle' => $subject->ciclo,
                'position' => $subject->orden_en_ciclo,
                'organization_unit' => $subject->unidad_organizacion_curricular?->label(),
                'credits' => $subject->creditos,
                'total_hours' => $subject->horas_totales,
                'hours_ac' => $subject->horas_ac,
                'hours_pae' => $subject->horas_pae,
                'hours_aa' => $subject->horas_aa,
                'prerequisites' => $requirementCodes('prerrequisito'),
                'corequisites' => $requirementCodes('correquisito'),
            ],
        ];
    }
}
