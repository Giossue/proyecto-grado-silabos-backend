<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use Illuminate\Validation\ValidationException;

/**
 * Lo que la programación de asignatura hereda en vez de volver a preguntar. El campus
 * se fija en la facultad y la modalidad en la carrera.
 * Cualquier materia puede apartarse de la modalidad base (tres materias en línea en
 * una carrera presencial) sin cambiar la modalidad aprobada de la carrera. El sílabo
 * copia ambos datos de la programación (I-35, I-36, I-37).
 */
class ScheduledSubjectInheritance
{
    /**
     * Modalidad propia de una materia: vacío significa «la de la carrera».
     *
     * @param  array<string, mixed>  $data
     */
    public function subjectModality(array $data): ?StudyModality
    {
        $value = $data['modality'] ?? null;

        return is_string($value) && $value !== '' ? StudyModality::from($value) : null;
    }

    /** Modalidad con la que se programa una materia. */
    public function modalityFor(Subject $subject): StudyModality
    {
        if ($subject->modalidad instanceof StudyModality) {
            return $subject->modalidad;
        }

        $subject->loadMissing('career');
        $modality = $subject->career->modalidad;
        if (! $modality instanceof StudyModality) {
            throw ValidationException::withMessages([
                'subject_id' => 'La carrera no tiene modalidad. Administración debe asignarla en Carreras antes de programar materias.',
            ]);
        }

        return $modality;
    }

    /** Campus fijado por la facultad de la carrera. */
    public function campusFor(Career $career): CampusLocation
    {
        $career->loadMissing('faculty');

        if (! $career->faculty?->campus instanceof CampusLocation) {
            throw ValidationException::withMessages([
                'subject_id' => 'La facultad de la carrera no tiene campus. Administración debe elegirlo en Facultades antes de programar materias.',
            ]);
        }

        return $career->faculty->campus;
    }
}
