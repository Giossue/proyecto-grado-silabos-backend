<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectProfile;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

class InheritMasterValues
{
    /** @param Collection<int, FieldDefinition> $fields */
    public function execute(Syllabus $syllabus, Collection $fields, Parallel $parallel): void
    {
        $parallel->loadMissing('subject.profile');

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
                'fichas_asignatura.descripcion',
                'fichas_asignatura.objetivo_general',
                'fichas_asignatura.objetivos_especificos',
                'fichas_asignatura.resultados_aprendizaje',
                'fichas_asignatura.habilidades_blandas',
                'fichas_asignatura.herramientas_tic',
                'fichas_asignatura.relacion_perfil_egreso',
                'fichas_asignatura.bibliografia_basica',
                'fichas_asignatura.bibliografia_complementaria' => $this->profileValue($parallel->subject->profile, $field->origen_maestro),
                default => null,
            };
            FieldValue::query()->create([
                'silabo_id' => $syllabus->id,
                'definicion_campo_id' => $field->id,
                'valor' => $value,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function profileValue(?SubjectProfile $profile, ?string $origin): ?array
    {
        if ($profile === null || $origin === null) {
            return null;
        }
        $key = substr($origin, strlen('fichas_asignatura.'));
        $value = $profile->getAttribute($key);
        if ($key === 'herramientas_tic') {
            $value = [
                ...($profile->herramientas_tic ?? []),
                ...array_map(
                    fn (array $item): string => $item['herramienta'].' — '.$item['caracteristica'],
                    $profile->herramientas_ia ?? [],
                ),
            ];
        }
        if (is_string($value) && trim($value) !== '') {
            return FreeText::fromText($value);
        }
        if (! is_array($value) || $value === []) {
            return null;
        }
        if ($key === 'habilidades_blandas') {
            return FreeText::fromRows([
                ['key' => 'nombre', 'label' => 'Habilidad'],
                ['key' => 'definicion', 'label' => 'Definición'],
            ], array_values($value));
        }

        return FreeText::fromRows([['key' => 'texto', 'label' => 'Contenido']], array_map(
            fn (string $text): array => ['texto' => $text],
            array_values($value),
        ));
    }
}
