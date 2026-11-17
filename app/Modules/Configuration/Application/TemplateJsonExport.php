<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldLimit;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Configuration\Presentation\Http\Requests\ImportTemplateStructureRequest;

final class TemplateJsonExport
{
    /** @return array<string, mixed> */
    public static function export(SyllabusTemplate $template): array
    {
        $definition = $template->definition();
        $conditions = $definition->fieldConditions();
        $limits = $definition->fieldLimits();

        return [
            '$schema' => route('template.schema'),
            'version' => ImportTemplateStructureRequest::FORMAT_VERSION,
            ...(($definition->toArray()['response_policy'] ?? null) === 'protected' ? ['response_policy' => 'protected'] : []),
            'document' => $definition->document(),
            'variables' => $definition->variables(),
            'blocks' => array_map(fn (array $block): array => [
                'label' => $block['label'],
                ...($block['props'] !== [] ? ['props' => $block['props']] : []),
                ...($block['value'] !== [] ? ['value' => $block['value']] : []),
                ...($block['rules'] !== [] ? ['rules' => $block['rules']] : []),
                ...(isset($block['catalog']) ? ['catalog' => $block['catalog']] : []),
                'layout' => DocumentHtml::toLines(['type' => 'doc', 'content' => $block['layout']]),
            ], $definition->blocks()),
            'required_sections' => $definition->requiredSections(),
            'library_sections' => $definition->librarySections(),
            'presets' => array_map(fn (array $preset): array => ['key' => $preset['key'], 'label' => $preset['label'], 'field' => $preset['field']], $definition->presets()),
            'sections' => $template->sections->map(fn (TemplateSection $section): array => [
                'key' => $section->clave,
                'title' => $section->titulo,
                'orientation' => $section->orientacion,
                'fields' => $section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(fn (FieldDefinition $field): array => [
                    'key' => $field->clave,
                    'label' => $field->etiqueta,
                    'quien_llena' => $field->quien_llena,
                    'content' => DocumentHtml::toLines($field->contenido),
                    ...(isset($conditions[$field->clave]) ? ['show_if' => $conditions[$field->clave]] : []),
                    ...(isset($limits[$field->clave]) && FieldLimit::allowed($field->quien_llena) ? ['max_length' => $limits[$field->clave]] : []),
                ])->all())->values()->all(),
            ])->values()->all(),
        ];
    }
}
