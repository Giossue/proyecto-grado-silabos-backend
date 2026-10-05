<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

class InheritMasterValues
{
    public function __construct(private readonly SubjectProfileFields $profiles) {}

    /** @param Collection<int, FieldDefinition> $fields */
    public function execute(Syllabus $syllabus, Collection $fields, Parallel $parallel): void
    {
        $parallel->loadMissing('subject');
        $profile = $this->profiles->values($parallel->subject->id);

        foreach ($fields->where('heredado', true) as $field) {
            $value = match ($field->origen_maestro) {
                'asignaturas' => [
                    'codigo' => $parallel->subject->codigo_asignatura,
                    'nombre' => $parallel->subject->nombre,
                    'ciclo' => $parallel->subject->ciclo,
                    'creditos' => $parallel->subject->creditos,
                    'horas_totales' => $parallel->subject->horas_totales,
                    'campus' => $parallel->campus_programacion,
                    'modalidad' => $parallel->modalidad->label(),
                ],
                FieldDefinition::ORIGIN_PROFILE => $profile[$field->id] ?? null,
                default => null,
            };
            FieldValue::query()->create([
                'silabo_id' => $syllabus->id,
                'definicion_campo_id' => $field->id,
                'valor' => $value,
            ]);
        }
    }
}
