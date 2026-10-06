<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\SyllabusTemplateCatalog;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;

class SaveTemplateSectionRequest extends ManageTemplatesRequest
{
    private const CONTENT_TYPES = ['free_text', 'template'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : $template;
        $section = $this->route('section');
        $sectionId = is_string($section) ? $section : null;
        $hasFields = is_array($this->input('fields')) && $this->input('fields') !== [];
        $hasPreset = $this->filled('preset');
        $requiresLegacyField = $sectionId === null && ! $hasFields && ! $hasPreset;

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
            'preset' => ['nullable', 'string', Rule::in(app(SyllabusTemplateCatalog::class)->keys())],
            'fields' => [Rule::requiredIf($sectionId === null && ! $this->filled('first_field_label') && ! $hasPreset), 'nullable', 'array', 'min:1', 'max:20'],
            'fields.*' => ['required', 'array:key,label,help,content_type'],
            'fields.*.label' => ['nullable', 'string', 'max:180'],
            'fields.*.key' => [
                'required',
                'string',
                'distinct',
                'regex:/^[a-z][a-z0-9_]*$/',
                'max:100',
                Rule::unique('definiciones_campo', 'clave_definicion_campo')
                    ->where('plantilla_id', $templateId)
                    ->where('vigente_en_plantilla', true),
            ],
            'fields.*.content_type' => ['required', Rule::in(self::CONTENT_TYPES)],
            'fields.*.help' => ['nullable', 'string', 'max:2000'],
            'first_field_label' => [Rule::requiredIf($requiresLegacyField), 'nullable', 'string', 'max:180'],
            'first_field_key' => [
                Rule::requiredIf($requiresLegacyField),
                'nullable',
                'string',
                'regex:/^[a-z][a-z0-9_]*$/',
                'max:120',
                Rule::unique('definiciones_campo', 'clave_definicion_campo')
                    ->where('plantilla_id', $templateId)
                    ->where('vigente_en_plantilla', true),
            ],
            'first_field_content_type' => [
                Rule::requiredIf($requiresLegacyField),
                'nullable',
                Rule::in(self::CONTENT_TYPES),
            ],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['preset' => 'plantilla de campo'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fields.*.content_type.in' => 'Elija un tipo de campo de la lista.',
            'first_field_content_type.in' => 'Elija un tipo de campo de la lista.',
        ];
    }
}
