<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Application\CurricularData;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectRequirement;

class AcademicContextSnapshot
{
    public function __construct(private readonly CurricularData $curricularData) {}

    /** @return array<string, mixed> */
    public function build(ScheduledSubject $scheduledSubject): array
    {
        $scheduledSubject->loadMissing([
            'subject.career.faculty',
            'subject.requirements.requirement',
            'subject.learningOutcomeBase.outcomes',
            'subject.learningOutcomeBase.confirmedBy',
            'academicPeriod',
        ]);
        $subject = $scheduledSubject->subject;
        $career = $subject->career;
        $requirementCodes = fn (string $type): array => $subject->requirements
            ->where('tipo', $type)
            ->map(fn (SubjectRequirement $requirement): string => $requirement->requirement->codigo_asignatura)
            ->sort()
            ->values()
            ->all();

        return [
            'schema_version' => 5,
            'curricular' => $this->curricularData->snapshot($career->id, $subject->id),
            'career' => [
                'id' => $career->id,
                'code' => $career->codigo_carrera,
                'name' => $career->nombre,
                'faculty' => $career->faculty?->nombre,
                'faculty_id' => $career->facultad_id,
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
                'learning_outcomes' => $subject->learningOutcomeBase?->snapshot(),
            ],
            'scheduled_subject' => [
                'id' => $scheduledSubject->id,
                'period' => $scheduledSubject->academicPeriod->codigo,
                'teaching_weeks' => $scheduledSubject->academicPeriod->semanas_lectivas,
                'campus' => $scheduledSubject->campus_programacion,
                'modality' => $scheduledSubject->modalidad->label(),
            ],
        ];
    }
}
