<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\RepeatableRow;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class BuildSyllabusChatContext
{
    /** Datos de la ficha que identifican a una persona: no aportan a las sugerencias. */
    private const PERSONAL_VARIABLES = ['nombre_docente', 'correo_docente'];

    public function __construct(
        private readonly AiEvidenceCollector $sources,
        private readonly CanonicalHasher $hasher,
    ) {}

    /**
     * @return list<array{
     *   id: string, type: string, label: string, excerpt: string, fingerprint: string,
     *   field_id: string|null, source_id: string|null, fragment_id: string|null
     * }>
     */
    public function build(Syllabus $syllabus, ?string $query = null, ?string $excludeFieldId = null): array
    {
        $syllabus->loadMissing(['values', 'rows', 'convocation']);
        $values = $syllabus->values->keyBy('definicion_campo_id');
        $rows = $syllabus->rows->groupBy('definicion_campo_id');
        $fieldItems = [];

        foreach (SyllabusTemplate::institutional()->sections->sortBy('posicion') as $section) {
            foreach ($section->blocks->sortBy('posicion') as $block) {
                foreach ($block->fields->sortBy('posicion') as $field) {
                    if ($field->id === $excludeFieldId) {
                        continue;
                    }
                    if (! $field->ia_habilitada) {
                        continue;
                    }
                    $excerpt = $block->configuredContentType() === 'institutional' && $field->heredado
                        ? $this->identificationContent($syllabus)
                        : $this->fieldContent($field, $values->get($field->id)?->valor, $rows->get($field->id));
                    if ($excerpt === '') {
                        continue;
                    }
                    $excerpt = mb_substr($excerpt, 0, (int) config('ai.limits.evidence_excerpt_characters'));
                    $fieldItems[] = [
                        'id' => (string) Str::uuid(),
                        'type' => 'seccion_silabo',
                        'label' => "{$section->titulo} · {$field->etiqueta}",
                        'excerpt' => $excerpt,
                        'fingerprint' => $this->hasher->hash($excerpt),
                        'field_id' => $field->id,
                        'source_id' => null,
                        'fragment_id' => null,
                    ];
                }
            }
        }

        $sourceItems = [];
        foreach ($this->sources->collect($syllabus, $query)['items'] as $source) {
            $sourceItems[] = [
                'id' => (string) Str::uuid(),
                'type' => 'fuente_academica',
                'label' => $source['source_name'],
                'excerpt' => $source['excerpt'],
                'fingerprint' => $source['fingerprint'],
                'field_id' => null,
                'source_id' => $source['source_id'],
                'fragment_id' => $source['fragment_id'],
            ];
        }

        $limit = max(1, (int) config('ai.limits.evidence_items'));
        $sourceQuota = $sourceItems === [] ? 0 : min(count($sourceItems), max(1, intdiv($limit, 2)));
        $fieldQuota = min(count($fieldItems), $limit - $sourceQuota);
        $remaining = $limit - $sourceQuota - $fieldQuota;
        $sourceQuota += min($remaining, count($sourceItems) - $sourceQuota);
        $remaining = $limit - $sourceQuota - $fieldQuota;
        $fieldQuota += min($remaining, count($fieldItems) - $fieldQuota);

        return array_merge(
            array_slice($fieldItems, 0, $fieldQuota),
            array_slice($sourceItems, 0, $sourceQuota),
        );
    }

    /**
     * La ficha institucional no guarda un valor propio: se lee desde las variables que la
     * pintan, sin marcas de unidad ni datos personales del docente.
     */
    private function identificationContent(Syllabus $syllabus): string
    {
        $values = TemplateVariables::resolve([
            'identification' => IdentificationCard::fromSyllabus($syllabus),
            'academic_context' => $syllabus->contexto_academico,
        ]);
        $lines = [];
        foreach (TemplateVariables::definitions() as $key => $definition) {
            $value = trim($values[$key] ?? '');
            if (isset($definition['equals']) || in_array($key, self::PERSONAL_VARIABLES, true) || $value === '') {
                continue;
            }
            $lines[] = "{$definition['label']}: {$value}";
        }

        return implode("\n", $lines);
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
