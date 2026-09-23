<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveFieldDefinitionRequest extends ManageTemplatesRequest
{
    private const TYPES = [
        'text',
        'table',
        'bulleted_list',
        'numbered_list',
        'boolean',
        'multiple_choice',
    ];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : $template;
        $field = $this->route('field');
        $fieldId = $field instanceof FieldDefinition ? $field->id : null;
        // Los bloques fijos (identificación, estado de revisión) no se crean desde la
        // paleta, pero sí se les edita la ayuda.
        $types = $fieldId === null ? self::TYPES : [...self::TYPES, 'institutional', 'flow'];

        return [
            'section_id' => [
                Rule::requiredIf($fieldId === null),
                'nullable',
                'uuid',
                static function (string $attribute, mixed $value, \Closure $fail) use ($template): void {
                    if (is_string($value)
                        && (! $template instanceof SyllabusTemplate || $template->section($value) === null)) {
                        $fail('La sección seleccionada no pertenece a la plantilla.');
                    }
                },
            ],
            'position' => ['nullable', 'integer', 'min:0'],
            'block_id' => [
                Rule::requiredIf($fieldId !== null),
                'nullable',
                'uuid',
                static function (string $attribute, mixed $value, \Closure $fail) use ($template): void {
                    if (is_string($value)
                        && (! $template instanceof SyllabusTemplate || $template->block($value) === null)) {
                        $fail('El bloque seleccionado no pertenece a la plantilla.');
                    }
                },
            ],
            'key' => [
                'required',
                'string',
                'regex:/^[a-z][a-z0-9_]*$/',
                $fieldId === null ? 'max:100' : 'max:120',
                Rule::unique('definiciones_campo', 'clave_definicion_campo')
                    ->where('plantilla_id', $templateId)
                    ->ignore($fieldId),
            ],
            'label' => ['required', 'string', 'max:180'],
            'help' => ['nullable', 'string', 'max:2000'],
            'content_type' => ['required', Rule::in($types)],
            'inherited' => ['nullable', 'boolean'],
            'master_source' => ['nullable', 'required_if:inherited,true', 'string', 'max:100'],
            'teacher_editable' => ['nullable', 'boolean'],
            'ai_enabled' => ['nullable', 'boolean'],
            'rules' => ['nullable', 'array'],
            'options' => ['nullable', 'array', 'min:2', 'max:50'],
            'options.*' => ['required', 'array:value,label'],
            'options.*.value' => ['required', 'string', 'max:120', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:180'],
            'document_marker' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('content_type') === 'multiple_choice'
                && count((array) $this->input('options', [])) < 2) {
                $validator->errors()->add('options', 'Agregue al menos dos opciones.');
            }
        }];
    }
}
