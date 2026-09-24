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
        'fixed',
    ];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : $template;
        $field = $this->route('field');
        $fieldId = $field instanceof FieldDefinition ? $field->id : null;
        // La ficha de identificación no se crea desde la paleta, pero sí se le edita la ayuda.
        // "Selección múltiple" ya no se ofrece; una parte existente de ese tipo se conserva.
        $types = $fieldId === null ? self::TYPES : [...self::TYPES, 'institutional', 'multiple_choice'];

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
                        $fail('La sección elegida no pertenece a la plantilla.');
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
            // El nombre de una parte es opcional: sin nombre no se imprime subtítulo.
            'label' => ['nullable', 'string', 'max:180'],
            'help' => ['nullable', 'string', 'max:2000'],
            'content_type' => ['required', Rule::in($types)],
            'inherited' => ['nullable', 'boolean'],
            'master_source' => ['nullable', 'required_if:inherited,true', 'string', 'max:100'],
            'teacher_editable' => ['nullable', 'boolean'],
            'ai_enabled' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array', 'min:2', 'max:50'],
            'options.*' => ['required', 'array:value,label'],
            'options.*.value' => ['required', 'string', 'max:120', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:180'],
            'document_marker' => ['nullable', 'string', 'max:160'],
            'page_orientation' => ['sometimes', 'nullable', 'in:portrait,landscape'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'content_type.in' => 'Elija un tipo de parte de la lista.',
            'page_orientation.in' => 'Elija hoja vertical u horizontal.',
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
