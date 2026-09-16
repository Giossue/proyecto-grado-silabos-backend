<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\RepeatableRow;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class BuildSyllabusChatContext
{
    public function __construct(
        private readonly AiEvidenceCollector $sources,
        private readonly CanonicalHasher $hasher,
        private readonly ResolveEffectiveAiConfiguration $effectiveAi,
    ) {}

    /**
     * @return list<array{
     *   id: string, type: string, label: string, excerpt: string, fingerprint: string,
     *   field_id: string|null, source_id: string|null
     * }>
     */
    public function build(Syllabus $syllabus): array
    {
        $syllabus->loadMissing(['template.sections.blocks.fields', 'values', 'rows', 'convocation']);
        $values = $syllabus->values->keyBy('definicion_campo_id');
        $rows = $syllabus->rows->groupBy('definicion_campo_id');
        $items = [];

        foreach ($syllabus->template->sections->sortBy('posicion') as $section) {
            foreach ($section->blocks->sortBy('posicion') as $block) {
                foreach ($block->fields->sortBy('posicion') as $field) {
                    if (! $this->effectiveAi->enabled($syllabus->carrera_id, $field)) {
                        continue;
                    }
                    $excerpt = $this->fieldContent($field, $values->get($field->id)?->valor, $rows->get($field->id));
                    if ($excerpt === '') {
                        continue;
                    }
                    $excerpt = mb_substr($excerpt, 0, (int) config('ai.limits.evidence_excerpt_characters'));
                    $items[] = [
                        'id' => (string) Str::uuid(),
                        'type' => 'seccion_silabo',
                        'label' => "{$section->titulo} · {$field->etiqueta}",
                        'excerpt' => $excerpt,
                        'fingerprint' => $this->hasher->hash($excerpt),
                        'field_id' => $field->id,
                        'source_id' => null,
                    ];
                }
            }
        }

        foreach ($this->sources->collect($syllabus)['items'] as $source) {
            $items[] = [
                'id' => (string) Str::uuid(),
                'type' => 'fuente_academica',
                'label' => $source['source_name'],
                'excerpt' => $source['excerpt'],
                'fingerprint' => $source['fingerprint'],
                'field_id' => null,
                'source_id' => $source['source_id'],
            ];
        }

        return array_slice($items, 0, (int) config('ai.limits.evidence_items'));
    }

    /** @param Collection<int, RepeatableRow>|null $rows */
    private function fieldContent(FieldDefinition $field, mixed $value, mixed $rows): string
    {
        if ($field->tipo === 'repetible') {
            $data = [];
            foreach (($rows ?? []) as $row) {
                $data[(int) $row->posicion] = $row->datos;
            }
            ksort($data);
            $data = array_values($data);

            return $data === [] ? '' : (json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', array_filter($value, 'is_scalar'));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
