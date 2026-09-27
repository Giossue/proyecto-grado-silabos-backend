<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\Academic\Application\CurricularData;
use App\Modules\Configuration\Domain\TableLayout;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;
use Illuminate\Support\Str;

/** Contexto fotografiado para proponer contenido de unidades; sin RAG ni datos personales. */
class BuildSyllabusChatContext
{
    public const VERSION = 'curricular-units-v1';

    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @return list<array{id: string, type: string, label: string, excerpt: string, fingerprint: string, field_id: string|null, source_id: null, fragment_id: null}> */
    public function build(Syllabus $syllabus): array
    {
        $syllabus->loadMissing('rows');
        $context = $syllabus->contexto_academico ?? [];
        $academic = [];
        foreach ([
            'career.name' => 'Carrera', 'subject.name' => 'Asignatura', 'subject.code' => 'Código',
            'subject.cycle' => 'Ciclo', 'subject.organization_unit' => 'Unidad curricular',
            'subject.credits' => 'Créditos', 'subject.hours_ac' => 'Horas ACD',
            'subject.hours_pae' => 'Horas APE', 'subject.hours_aa' => 'Horas AA',
            'scheduled_subject.teaching_weeks' => 'Semanas lectivas',
        ] as $path => $label) {
            $value = data_get($context, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $academic[] = $label.': '.$value;
            }
        }
        $items = [];
        if ($academic !== []) {
            $items[] = $this->item('contexto_academico', 'Carrera y asignatura', implode("\n", $academic));
        }
        $definitions = data_get($context, 'curricular.definitions', []);
        $values = data_get($context, 'curricular.values', []);
        $curricularItems = [];
        foreach (is_array($definitions) ? $definitions : [] as $key => $definition) {
            if (! is_array($definition) || ! is_array($values) || ! array_key_exists($key, $values)) {
                continue;
            }
            $content = CurricularData::display($values[$key], $definition);
            if ($content !== '') {
                $curricularItems[] = $this->item('dato_curricular', (string) ($definition['label'] ?? $key), $content);
            }
        }
        // Compatibilidad con sílabos anteriores a la importación genérica.
        if (! is_array($values) || ! array_key_exists('resultados_aprendizaje', $values)) {
            $outcomes = data_get($context, 'subject.learning_outcomes.outcomes', []);
            $texts = is_array($outcomes) ? array_filter(array_column($outcomes, 'text'), 'is_string') : [];
            if ($texts !== []) {
                $curricularItems[] = $this->item('dato_curricular', 'Resultados de aprendizaje confirmados', implode("\n", $texts));
            }
        }
        foreach (SyllabusTemplate::institutional()->sections->sortBy('posicion') as $section) {
            foreach ($section->blocks->sortBy('posicion') as $block) {
                $layout = TableLayout::fromBlock($block);
                if ($layout === null) {
                    continue;
                }
                // En TableLayout, repeat agrupa por unidad; las filas repetibles genéricas no lo habilitan.
                $groupsByUnit = $layout['repeat']['enabled'];
                if (! TableLayout::isPlanning($layout) && ! $groupsByUnit) {
                    continue;
                }
                foreach ($block->fields->sortBy('posicion') as $field) {
                    if ($field->tipo !== 'repetible') {
                        continue;
                    }
                    $rows = $syllabus->rows->where('definicion_campo_id', $field->id)->sortBy('posicion')->pluck('datos')->values()->all();
                    $content = $this->hasher->json(['columns' => $layout['columns'], 'unit_fields' => $layout['header_fields'], 'rows' => $rows]);
                    $items[] = $this->item('seccion_silabo', $field->displayName(), $content, $field->id);
                }
            }
        }

        return array_slice([...$items, ...$curricularItems], 0, max(1, (int) config('ai.limits.evidence_items')));
    }

    /** @return array{id: string, type: string, label: string, excerpt: string, fingerprint: string, field_id: string|null, source_id: null, fragment_id: null} */
    private function item(string $type, string $label, string $content, ?string $fieldId = null): array
    {
        $excerpt = mb_substr($content, 0, (int) config('ai.limits.evidence_excerpt_characters'));

        return ['id' => (string) Str::uuid(), 'type' => $type, 'label' => $label, 'excerpt' => $excerpt,
            'fingerprint' => $this->hasher->hash($excerpt), 'field_id' => $fieldId, 'source_id' => null, 'fragment_id' => null];
    }
}
