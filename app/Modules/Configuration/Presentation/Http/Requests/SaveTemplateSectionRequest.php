<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTemplateSectionRequest extends ManageTemplatesRequest
{
    private const CONTENT_TYPES = ['text', 'table', 'bulleted_list', 'numbered_list', 'boolean', 'multiple_choice'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : $template;
        $section = $this->route('section');
        $sectionId = $section instanceof TemplateSection ? $section->id : null;
        $hasFields = is_array($this->input('fields')) && $this->input('fields') !== [];
        $requiresLegacyField = $sectionId === null && ! $hasFields;

        return [
            'title' => ['required', 'string', 'max:180'],
            'key' => [
                Rule::requiredIf($sectionId === null),
                'nullable',
                'string',
                'regex:/^[a-z][a-z0-9_]*$/',
                'max:100',
                Rule::unique('secciones_plantilla', 'clave_seccion_plantilla')
                    ->where('plantilla_id', $templateId)
                    ->ignore($sectionId),
            ],
            'fields' => [Rule::requiredIf($sectionId === null && ! $this->filled('first_field_label')), 'nullable', 'array', 'min:1', 'max:20'],
            'fields.*' => ['required', 'array:key,label,help,content_type,options,ai_enabled,ai_coordinator_configurable'],
            'fields.*.label' => ['required', 'string', 'max:180'],
            'fields.*.key' => [
                'required',
                'string',
                'distinct',
                'regex:/^[a-z][a-z0-9_]*$/',
                'max:100',
                Rule::unique('definiciones_campo', 'clave_definicion_campo')->where('plantilla_id', $templateId),
            ],
            'fields.*.content_type' => ['required', Rule::in(self::CONTENT_TYPES)],
            'fields.*.help' => ['nullable', 'string', 'max:2000'],
            'fields.*.options' => ['nullable', 'array', 'min:2', 'max:50'],
            'fields.*.options.*' => ['required', 'array:value,label'],
            'fields.*.options.*.value' => ['required', 'string', 'max:120', 'distinct'],
            'fields.*.options.*.label' => ['required', 'string', 'max:180'],
            'fields.*.ai_enabled' => ['nullable', 'boolean'],
            'fields.*.ai_coordinator_configurable' => ['nullable', 'boolean'],
            'first_field_label' => [Rule::requiredIf($requiresLegacyField), 'nullable', 'string', 'max:180'],
            'first_field_key' => [
                Rule::requiredIf($requiresLegacyField),
                'nullable',
                'string',
                'regex:/^[a-z][a-z0-9_]*$/',
                'max:120',
                Rule::unique('definiciones_campo', 'clave_definicion_campo')->where('plantilla_id', $templateId),
            ],
            'first_field_content_type' => [
                Rule::requiredIf($requiresLegacyField),
                'nullable',
                Rule::in(self::CONTENT_TYPES),
            ],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('fields', []) as $index => $field) {
                if (is_array($field)
                    && ($field['content_type'] ?? null) === 'multiple_choice'
                    && count((array) ($field['options'] ?? [])) < 2) {
                    $validator->errors()->add("fields.{$index}.options", 'Agregue al menos dos opciones.');
                }
            }
        }];
    }
}
