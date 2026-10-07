<?php

namespace App\Modules\Academic\Application;

use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Support\Collection;

/**
 * Ficha de asignatura: los campos de la plantilla marcados «se llena desde la ficha» y sus valores,
 * que se guardan una sola vez por materia en `valores_campo` (I-189).
 */
class SubjectProfileFields
{
    /** @return Collection<int, FieldDefinition> */
    public function fields(): Collection
    {
        $template = SyllabusTemplate::query()->first();
        if ($template === null) {
            return new Collection;
        }

        return $template->sections
            ->flatMap(fn ($section) => $section->blocks->flatMap(fn ($block) => $block->fields))
            ->filter(fn (FieldDefinition $field): bool => $field->fromProfile() && $field->fieldType()->key() === 'ficha')
            ->values();
    }

    /** @return array<string, array<string, mixed>|null> valor por definición de campo */
    public function values(string $subjectId): array
    {
        return FieldValue::query()
            ->where('asignatura_id', $subjectId)
            ->get()
            ->mapWithKeys(fn (FieldValue $value): array => [$value->definicion_campo_id => FreeText::isDocument($value->valor) ? FreeText::normalize($value->valor) : null])
            ->all();
    }

    /**
     * @param  array<int, string>  $subjectIds
     * @return array<string, bool> ficha completa por materia: todos los campos de la ficha tienen texto
     */
    public function completeBySubject(array $subjectIds): array
    {
        $fieldIds = $this->fields()->pluck('id')->all();
        $filled = [];
        if ($fieldIds !== [] && $subjectIds !== []) {
            FieldValue::query()
                ->whereIn('asignatura_id', $subjectIds)
                ->whereIn('definicion_campo_id', $fieldIds)
                ->get()
                ->each(function (FieldValue $value) use (&$filled): void {
                    if (FreeText::isDocument($value->valor) && ! FreeText::isEmpty(FreeText::normalize($value->valor))) {
                        $filled[$value->asignatura_id][$value->definicion_campo_id] = true;
                    }
                });
        }

        $complete = [];
        foreach ($subjectIds as $subjectId) {
            $complete[$subjectId] = count($filled[$subjectId] ?? []) === count($fieldIds);
        }

        return $complete;
    }
}
