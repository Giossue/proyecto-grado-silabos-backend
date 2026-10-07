<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\FieldOptions;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;

class SyllabusSnapshot
{
    /** Versión 7 (I-195): cada sección lleva `fields[]` planos con `type`, `config` y valor resuelto. */
    public const VERSION = 7;

    /** @return array<string, mixed> */
    public function build(Syllabus $syllabus): array
    {
        $syllabus->loadMissing('values');
        $values = $syllabus->values->keyBy('definicion_campo_id');

        $identification = IdentificationCard::fromSyllabus($syllabus);

        $template = SyllabusTemplate::institutional();
        $definitions = FieldDefinition::query()->where('plantilla_id', $template->id)->get();
        $hidden = array_flip(FieldConditions::hiddenKeys(
            $definitions,
            $definitions->mapWithKeys(fn (FieldDefinition $field): array => [$field->clave => $values->get($field->id)?->valor])->all(),
        ));

        $context = ['identification' => $identification, 'academic_context' => $syllabus->contexto_academico];

        return [
            'schema_version' => self::VERSION,
            'template_id' => $template->id,
            'template_name' => SyllabusTemplate::INSTITUTIONAL_NAME,
            'document_mapping' => $template->mapeo_documento,
            'academic_context' => $syllabus->contexto_academico,
            'identification' => $identification,
            'template_variables' => TemplateVariables::resolve(['identification' => $identification, 'academic_context' => $syllabus->contexto_academico]),
            'sections' => $template->sections
                ->map(fn (TemplateSection $section): array => [
                    'key' => $section->clave,
                    'title' => $section->titulo,
                    'fields' => $section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(function (FieldDefinition $field) use ($values, $context): array {
                        $type = $field->fieldType();
                        $config = $field->typeConfig();

                        return [
                            'definition_id' => $field->id,
                            'key' => $field->clave,
                            'label' => $field->etiqueta,
                            'type' => $type->key(),
                            'legacy' => $field->hasLegacyClass(),
                            'config' => $config,
                            'value' => $type->toSnapshot($values->get($field->id)?->valor, $config, $context),
                        ];
                    }))->values()->all(),
                    'blocks' => $section->blocks->map(fn (TemplateBlock $block): array => [
                        'key' => $block->clave,
                        'title' => $block->titulo,
                        'content_type' => $block->contentType(),
                        'page_orientation' => $block->pageOrientation(),
                        'compact' => $block->isCompact(),
                        'document' => $block->configuracion['document'] ?? null,
                        'fields' => $block->fields->map(fn (FieldDefinition $field): array => [
                            'show_when' => FieldConditions::normalize($field->mostrar_si),
                            'hidden' => isset($hidden[$field->clave]),
                            'definition_id' => $field->id,
                            'key' => $field->clave,
                            'label' => $field->etiqueta,
                            'type' => $field->tipo,
                            'options' => FieldOptions::options($field),
                            'inherited' => $field->heredado,
                            'master_source' => $field->origen_maestro,
                            'value' => $values->get($field->id)?->valor,
                        ])->values()->all(),
                    ])->values()->all(),
                ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(Syllabus $syllabus, array $snapshot): void
    {
        $fieldIds = [];
        foreach ($this->sections($snapshot) as $section) {
            // v7: campos planos; los automáticos no guardan valor (se conservan sin tocar). v6 y anteriores: bloques.
            $groups = ($snapshot['schema_version'] ?? 0) >= 7 ? [$section] : $this->blocks($section);
            foreach ($groups as $block) {
                foreach ($this->fields($block) as $field) {
                    $definitionId = $field['definition_id'] ?? null;
                    if (! is_string($definitionId)) {
                        continue;
                    }
                    $fieldIds[] = $definitionId;
                    if (($field['type'] ?? null) === 'automatico') {
                        continue;
                    }
                    FieldValue::query()->updateOrCreate([
                        'silabo_id' => $syllabus->id,
                        'definicion_campo_id' => $definitionId,
                    ], [
                        'valor' => $field['value'] ?? null,
                    ]);
                }
            }
        }

        FieldValue::query()->where('silabo_id', $syllabus->id)->whereNotIn('definicion_campo_id', $fieldIds)->delete();
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function sections(array $value): array
    {
        return $this->arrayList($value['sections'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function blocks(array $value): array
    {
        return $this->arrayList($value['blocks'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function fields(array $value): array
    {
        return $this->arrayList($value['fields'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
