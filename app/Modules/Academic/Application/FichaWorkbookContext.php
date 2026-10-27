<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\FichaWorkbookDefinition;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use Illuminate\Validation\ValidationException;

final class FichaWorkbookContext
{
    public function __construct(private readonly SubjectProfileFields $profile, private readonly FichaFieldValues $values) {}

    public function for(string $careerId): FichaWorkbookDefinition
    {
        $career = Career::query()->where('activo', true)->findOrFail($careerId);
        $fields = [];
        foreach ($this->profile->fields() as $index => $field) {
            $schema = $this->values->schema($field);
            $prefix = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' ';
            $name = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':', "'"], ' ', $schema['label']));
            $fields[] = [...$schema,
                'id' => $field->id,
                'section' => $field->block?->section->titulo ?? '',
                'sheet' => $prefix.mb_substr($name !== '' ? $name : 'Campo', 0, 31 - mb_strlen($prefix)),
                'definition_hash' => hash('sha256', json_encode($field->contenido, JSON_THROW_ON_ERROR)),
            ];
        }
        $subjects = Subject::query()->where('carrera_id', $careerId)->where('activo', true)->where('genera_silabo', true)
            ->orderBy('ciclo')->orderBy('orden_en_ciclo')->orderBy('codigo_asignatura')->orderBy('asignatura_id')->get()
            ->map(fn (Subject $subject): array => ['id' => $subject->id, 'code' => $subject->codigo_asignatura, 'name' => $subject->nombre, 'cycle' => $subject->ciclo])->values()->all();
        if ($fields === [] || $subjects === []) {
            throw ValidationException::withMessages(['file' => 'Para generar el Excel debe haber materias activas y campos de la plantilla asignados a Coordinación.']);
        }

        return new FichaWorkbookDefinition($careerId, $career->nombre, $fields, array_values($subjects));
    }
}
