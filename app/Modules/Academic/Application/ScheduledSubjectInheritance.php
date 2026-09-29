<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use Illuminate\Validation\ValidationException;

class ScheduledSubjectInheritance
{
    /** @param  array<string, mixed>  $data */
    public function subjectModality(array $data): ?StudyModality
    {
        $value = $data['modality'] ?? null;

        return is_string($value) && $value !== '' ? StudyModality::from($value) : null;
    }

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
