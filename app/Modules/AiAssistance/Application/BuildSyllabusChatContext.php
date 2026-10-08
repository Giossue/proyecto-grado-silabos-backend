<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\Configuration\Domain\FieldTypes\UnitsFieldType;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;
use Illuminate\Support\Str;

class BuildSyllabusChatContext
{
    public const VERSION = 'free-text-v3';

    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @return list<array{id: string, type: string, label: string, excerpt: string, fingerprint: string, field_id: string|null, source_id: null, fragment_id: null}> */
    public function build(Syllabus $syllabus): array
    {
        $syllabus->loadMissing('values');
        $context = $syllabus->contexto_academico ?? [];
        $academic = [];
        foreach ([
            'career.name' => 'Carrera', 'subject.name' => 'Asignatura', 'subject.code' => 'Código',
            'subject.cycle' => 'Ciclo', 'subject.organization_unit' => 'Unidad curricular',
            'subject.credits' => 'Créditos', 'subject.hours_ac' => 'Horas ACD',
            'subject.hours_pae' => 'Horas APE', 'subject.hours_aa' => 'Horas AA',
        ] as $path => $label) {
            $value = data_get($context, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $academic[] = $label.': '.$value;
            }
        }
        // La planificación del sílabo siempre es de 16 semanas, aunque el período dure más.
        $academic[] = 'Semanas de planificación: '.UnitsFieldType::MAX_WEEKS;
        $items = [];
        if ($academic !== []) {
            $items[] = $this->item('contexto_academico', 'Carrera y asignatura', implode("\n", $academic));
        }
        foreach (SyllabusTemplate::institutional()->sections->sortBy('posicion') as $section) {
            foreach ($section->blocks->sortBy('posicion') as $block) {
                foreach ($block->fields->sortBy('posicion') as $field) {
                    $value = $syllabus->values->firstWhere('definicion_campo_id', $field->id)?->valor;
                    $type = $field->fieldType()->key();
                    $content = match (true) {
                        in_array($type, ['texto', 'ficha'], true) && FreeText::isDocument($value) => trim(FreeText::plainText(FreeText::normalize($value))),
                        $type === 'unidades' && is_array($value) => $this->units($value),
                        default => '',
                    };
                    if ($content !== '') {
                        $items[] = $this->item($type === 'unidades' ? 'planificacion' : 'seccion_silabo', $field->displayName() !== '' ? $field->displayName() : $section->titulo, $content, $field->id);
                    }
                }
            }
        }

        return array_slice($items, 0, max(1, (int) config('ai.limits.evidence_items')));
    }

    /** Resumen de la planificación ya escrita: nombre, resultado y temas de cada unidad. */
    private function units(array $value): string
    {
        $lines = [];
        foreach (array_values(array_filter($value, 'is_array')) as $index => $unit) {
            $topics = array_filter(array_map(fn (mixed $week): string => is_array($week) && is_string($week['contenidos'] ?? null) ? trim($week['contenidos']) : '', is_array($unit['semanas'] ?? null) ? $unit['semanas'] : []));
            $lines[] = 'Unidad '.($index + 1).': '.trim((string) ($unit['nombre'] ?? ''))
                .(trim((string) ($unit['resultado'] ?? '')) !== '' ? ' — Resultado: '.trim((string) $unit['resultado']) : '')
                .($topics !== [] ? ' — Temas: '.implode('; ', $topics) : '');
        }

        return implode("\n", $lines);
    }

    /** @return array{id: string, type: string, label: string, excerpt: string, fingerprint: string, field_id: string|null, source_id: null, fragment_id: null} */
    private function item(string $type, string $label, string $content, ?string $fieldId = null): array
    {
        $excerpt = mb_substr($content, 0, (int) config('ai.limits.evidence_excerpt_characters'));

        return ['id' => (string) Str::uuid(), 'type' => $type, 'label' => $label, 'excerpt' => $excerpt,
            'fingerprint' => $this->hasher->hash($excerpt), 'field_id' => $fieldId, 'source_id' => null, 'fragment_id' => null];
    }
}
