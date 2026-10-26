<?php

namespace App\Modules\Academic\Application;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Support\Collection;

class SubjectProfileFields
{
    /**
     * @return Collection<int, FieldDefinition>
     */
    public function fields(): Collection
    {
        $template = SyllabusTemplate::query()->first();
        if ($template === null) {
            return new Collection;
        }

        return $template->sections
            ->flatMap(fn ($section) => $section->blocks->flatMap(fn ($block) => $block->fields))
            ->filter(fn (FieldDefinition $field): bool => $field->fromProfile())
            ->each(fn (FieldDefinition $field) => $field->setRelation('template', $template))
            ->values();
    }

    /**
     * @return array<string, array<string, mixed>|null> valor por definición de campo
     */
    public function values(string $subjectId): array
    {
        return FieldValue::query()
            ->where('asignatura_id', $subjectId)
            ->get()
            ->mapWithKeys(fn (FieldValue $value): array => [$value->definicion_campo_id => FreeText::isDocument($value->valor) ? FieldDocument::normalize($value->valor) : null])
            ->all();
    }

    /**
     * @return array<string, bool> el docente puede editar el campo, por definición de campo
     */
    public function editable(string $subjectId): array
    {
        return FieldValue::query()
            ->where('asignatura_id', $subjectId)
            ->where('editable_docente', true)
            ->pluck('definicion_campo_id')
            ->mapWithKeys(fn (string $fieldId): array => [$fieldId => true])
            ->all();
    }

    /**
     * @param  array<int, string>  $subjectIds
     * @return array<string, bool> ficha completa por materia: todos los campos de la ficha tienen texto
     */
    public function completeBySubject(array $subjectIds): array
    {
        return array_map(fn (array $progress): bool => $progress['filled'] === $progress['total'], $this->progressBySubject($subjectIds));
    }

    /**
     * @param  array<int, string>  $subjectIds
     * @return array<string, array{filled: int, total: int}>
     */
    public function progressBySubject(array $subjectIds): array
    {
        $fieldIds = $this->fields()->pluck('id')->all();
        $filled = [];
        if ($fieldIds !== [] && $subjectIds !== []) {
            FieldValue::query()
                ->whereIn('asignatura_id', $subjectIds)
                ->whereIn('definicion_campo_id', $fieldIds)
                ->get()
                ->each(function (FieldValue $value) use (&$filled): void {
                    if ($value->editable_docente || (FreeText::isDocument($value->valor) && ! FreeText::isEmpty(FieldDocument::normalize($value->valor)))) {
                        $filled[$value->asignatura_id][$value->definicion_campo_id] = true;
                    }
                });
        }

        $progress = [];
        foreach ($subjectIds as $subjectId) {
            $progress[$subjectId] = ['filled' => count($filled[$subjectId] ?? []), 'total' => count($fieldIds)];
        }

        return $progress;
    }
}
