<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;

class SyllabusSnapshot
{
    public const VERSION = 8;

    /**
     * @return array<string, mixed>
     */
    public function build(Syllabus $syllabus): array
    {
        $values = $syllabus->values()->get()->keyBy('definicion_campo_id');
        $template = SyllabusTemplate::institutional();
        $identification = IdentificationCard::fromSyllabus($syllabus);
        $variables = TemplateVariables::resolve(['identification' => $identification, 'academic_context' => $syllabus->contexto_academico]);

        return [
            'schema_version' => self::VERSION,
            'template_id' => $template->id,
            'template_name' => SyllabusTemplate::INSTITUTIONAL_NAME,
            'document_mapping' => [...($template->mapeo_documento ?? []), 'title_block' => TemplateTitleBlock::defaults()],
            'academic_context' => $syllabus->contexto_academico,
            'identification' => $identification,
            'template_variables' => $variables,
            'sections' => $this->archivedSnapshotSections($syllabus, $variables) ?? $template->sections->map(fn (TemplateSection $section): array => [
                'key' => $section->clave,
                'title' => $section->titulo,
                'orientation' => $section->orientacion,
                'fields' => $section->blocks->flatMap(fn ($block) => $block->fields->map(function (FieldDefinition $field) use ($values, $variables): array {
                    $document = $field->quien_llena === FieldDefinition::INSTITUTIONAL
                        ? $field->contenido : (($values->get($field->id) instanceof FieldValue ? $values->get($field->id)->valor : $field->contenido));
                    $document = FieldDocument::normalize($document);

                    return [
                        'definition_id' => $field->id,
                        'key' => $field->clave,
                        'label' => $field->etiqueta,
                        'quien_llena' => $field->quien_llena,
                        'document' => $document,
                        'value' => FieldDocument::resolve($document, $variables),
                    ];
                })->all())->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $variables
     * @return list<array<string, mixed>>|null
     */
    private function archivedSnapshotSections(Syllabus $syllabus, array $variables): ?array
    {
        $sections = SyllabusDocumentFields::archivedSections($syllabus);
        if ($sections === null) {
            return null;
        }
        foreach ($sections as &$section) {
            foreach ($section['fields'] as &$field) {
                $field['value'] = FieldDocument::resolve($field['document'], $variables);
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(Syllabus $syllabus, array $snapshot): void
    {
        $fieldIds = [];
        foreach ($snapshot['sections'] ?? [] as $section) {
            $groups = ($snapshot['schema_version'] ?? 0) >= 7 ? [$section] : ($section['blocks'] ?? []);
            foreach ($groups as $group) {
                foreach ($group['fields'] ?? [] as $field) {
                    $definitionId = $field['definition_id'] ?? null;
                    if (! is_string($definitionId)) {
                        continue;
                    }
                    $fieldIds[] = $definitionId;
                    if (($field['quien_llena'] ?? null) === FieldDefinition::INSTITUTIONAL || in_array($field['type'] ?? null, ['automatico', 'fijo'], true)) {
                        continue;
                    }
                    FieldValue::query()->updateOrCreate([
                        'silabo_id' => $syllabus->id,
                        'definicion_campo_id' => $definitionId,
                    ], ['valor' => $field['document'] ?? $field['value'] ?? null]);
                }
            }
        }
        FieldValue::query()->where('silabo_id', $syllabus->id)->whereNotIn('definicion_campo_id', $fieldIds)->delete();
    }
}
