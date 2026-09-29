<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

class InheritMasterValues
{
    /** @param Collection<int, FieldDefinition> $fields */
    public function execute(Syllabus $syllabus, Collection $fields, ScheduledSubject $scheduledSubject): void
    {
        $scheduledSubject->loadMissing('subject');

        foreach ($fields->where('heredado', true) as $field) {
            $value = match ($field->origen_maestro) {
                'asignaturas' => [
                    'codigo' => $scheduledSubject->subject->codigo_asignatura,
                    'nombre' => $scheduledSubject->subject->nombre,
                    'ciclo' => $scheduledSubject->subject->ciclo,
                    'creditos' => $scheduledSubject->subject->creditos,
                    'horas_totales' => $scheduledSubject->subject->horas_totales,
                    'campus' => $scheduledSubject->campus_programacion,
                    'modalidad' => $scheduledSubject->modalidad->label(),
                ],
                default => null,
            };
            FieldValue::query()->create([
                'silabo_id' => $syllabus->id,
                'definicion_campo_id' => $field->id,
                'valor' => $value,
                'heredado' => true,
                'origen' => $field->origen_maestro,
            ]);
        }
    }
}
