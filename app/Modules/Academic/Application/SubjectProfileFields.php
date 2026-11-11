<?php

namespace App\Modules\Academic\Application;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\ResponseKind;
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
        return $this->templateFields()->filter(fn (FieldDefinition $field): bool => $field->career() && FieldResponse::writable($field->contenido))->values();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function templates(string $subjectId): array
    {
        $stored = FieldValue::query()->where('asignatura_id', $subjectId)->get()->keyBy('definicion_campo_id');
        if ($stored->isEmpty()) {
            return [];
        }
        $templates = [];
        foreach (FieldDefinition::query()->whereKey($stored->keys()->all())->where('quien_llena', '!=', FieldDefinition::INSTITUTIONAL)->get() as $field) {
            $template = self::templateOf($field, $stored->get($field->id)?->valor);
            if ($template !== null) {
                $templates[(string) $field->id] = $template;
            }
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function templateOf(FieldDefinition $field, mixed $stored): ?array
    {
        $template = FieldResponse::templateFor($field->contenido, $stored);

        return $template == $field->contenido || $template == FieldResponse::template(FieldDocument::normalize($field->contenido)) ? null : $template;
    }

    /**
     * @param  array<string, array<string, mixed>>  $templates
     */
    public static function teacherEditable(FieldDefinition $field, array $templates): bool
    {
        return $field->career() && (! isset($templates[$field->id]) || FieldResponse::open($templates[$field->id]));
    }

    /**
     * @return array<string, int>
     */
    public function positions(): array
    {
        return array_flip($this->templateFields()->pluck('id')->all());
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function templateFields(): Collection
    {
        $template = SyllabusTemplate::query()->first();
        if ($template === null) {
            return new Collection;
        }

        return $template->sections
            ->flatMap(fn ($section) => $section->blocks->flatMap(fn ($block) => $block->fields))
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
     * @param  array<int, string>  $subjectIds
     * @return array<string, bool> proyecto de carrera completo por materia: ningún espacio de elegir quedó sin opciones
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
        $fields = $this->fields();
        $missing = [];
        foreach ($fields as $field) {
            if (ResponseKind::pending(FieldDocument::normalize($field->contenido))['without_options'] > 0) {
                $missing[(string) $field->id] = true;
            }
        }
        $stored = $fields->isEmpty() || $subjectIds === [] ? collect() : FieldValue::query()
            ->whereIn('asignatura_id', $subjectIds)
            ->whereIn('definicion_campo_id', $fields->pluck('id')->all())
            ->get()
            ->groupBy('asignatura_id');
        $progress = [];
        foreach ($subjectIds as $subjectId) {
            $rows = ($stored->get($subjectId) ?? collect())->keyBy('definicion_campo_id');
            $pending = 0;
            foreach ($fields as $field) {
                $template = self::templateOf($field, $rows->get($field->id)?->valor);
                if ($template !== null ? ResponseKind::pending($template)['without_options'] > 0 : isset($missing[(string) $field->id])) {
                    $pending++;
                }
            }
            $progress[$subjectId] = ['filled' => $fields->count() - $pending, 'total' => $fields->count()];
        }

        return $progress;
    }
}
