<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\CurricularFieldOptions;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTemplateSectionRequest extends ManageTemplatesRequest
{
    private const CONTENT_TYPES = ['text', 'table', 'bulleted_list', 'numbered_list', 'boolean', 'fixed', 'multiple_choice'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : $template;
        $section = $this->route('section');
        $sectionId = is_string($section) ? $section : null;
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
                static function (string $attribute, mixed $value, \Closure $fail) use ($sectionId, $template): void {
                    if ($template instanceof SyllabusTemplate
                        && $template->sections()->contains(
                            fn ($section): bool => $section->clave === $value && $section->id !== $sectionId,
                        )) {
                        $fail('La clave ya está siendo utilizada por otra sección.');
                    }
                },
            ],
            'fields' => [Rule::requiredIf($sectionId === null && ! $this->filled('first_field_label')), 'nullable', 'array', 'min:1', 'max:20'],
            'fields.*' => ['required', 'array:key,label,help,content_type,options,options_source,options_sources,ai_enabled'],
            'fields.*.label' => ['nullable', 'string', 'max:180'],
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
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options_source' => ['sometimes', 'nullable', 'string', 'max:140'],
            'fields.*.options_sources' => ['sometimes', 'array', 'list', 'max:20'],
            'fields.*.options_sources.*' => ['required', 'string', 'max:140'],
            'fields.*.options.*' => ['required', 'array:value,label'],
            'fields.*.options.*.value' => ['required', 'string', 'max:120', 'distinct'],
            'fields.*.options.*.label' => ['required', 'string', 'max:180'],
            'fields.*.ai_enabled' => ['nullable', 'boolean'],
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
                Rule::in(array_diff(self::CONTENT_TYPES, ['multiple_choice'])),
            ],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fields.*.content_type.in' => 'Elija un tipo de parte de la lista.',
            'first_field_content_type.in' => 'Elija un tipo de parte de la lista.',
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('fields', []) as $index => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $sources = CurricularFieldOptions::fromAttributes($field);
                if (($field['content_type'] ?? null) === 'multiple_choice' && $sources === []) {
                    $validator->errors()->add("fields.{$index}.content_type", 'Seleccione un catálogo curricular para la nueva selección múltiple.');
                }
                foreach ($sources as $source) {
                    if (($field['content_type'] ?? null) !== 'multiple_choice' || ! CurricularFieldOptions::sourceIsAllowed($source)) {
                        $validator->errors()->add("fields.{$index}.options_sources", 'Seleccione listas curriculares para una pregunta de selección.');
                    }
                }
                if ($sources !== [] && ($field['options'] ?? null) !== null && $field['options'] !== []) {
                    $validator->errors()->add("fields.{$index}.options", 'Las opciones provienen de la lista curricular.');
                }
            }
        }];
    }
}
