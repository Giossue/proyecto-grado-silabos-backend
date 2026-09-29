<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\CurricularFieldOptions;
use App\Modules\Configuration\Application\FieldConditions;
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
        'multiple_choice',
        // Formas de una parte nueva (I-156).
        'free_text',
        'template',
    ];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : $template;
        $field = $this->route('field');
        $fieldId = $field instanceof FieldDefinition ? $field->id : null;
        // La ficha de identificación no se crea desde la paleta, pero sí se le edita la ayuda.
        $types = $fieldId === null ? self::TYPES : [...self::TYPES, 'institutional'];

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
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['required', 'array:value,label'],
            'options.*.value' => ['required', 'string', 'max:120', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:180'],
            'options_source' => ['sometimes', 'nullable', 'string', 'max:140'],
            'options_sources' => ['sometimes', 'array', 'list', 'max:20'],
            'options_sources.*' => ['required', 'string', 'max:140', 'distinct'],
            'document_marker' => ['nullable', 'string', 'max:160'],
            'page_orientation' => ['sometimes', 'nullable', 'in:portrait,landscape'],
            // «Mostrar solo si»: null es «Siempre».
            'show_when' => ['sometimes', 'nullable', 'array:key,value'],
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
            $field = $this->route('field');
            $sources = $this->has('options_sources') || $this->has('options_source')
                ? CurricularFieldOptions::fromAttributes($this->all())
                : ($field instanceof FieldDefinition ? CurricularFieldOptions::sources($field) : []);
            foreach ($sources as $source) {
                if (! CurricularFieldOptions::sourceIsAllowed($source)) {
                    $validator->errors()->add('options_sources', 'Seleccione listas curriculares disponibles.');
                }
            }
            if ($sources !== [] && $this->input('content_type') !== 'multiple_choice'
                && (! $field instanceof FieldDefinition || ! in_array($field->tipo, CurricularFieldOptions::CHOICE_TYPES, true))) {
                $validator->errors()->add('options_sources', 'Solo una pregunta de selección admite opciones curriculares.');
            }
            if (! $field instanceof FieldDefinition && $this->input('content_type') === 'multiple_choice' && $sources === []) {
                $validator->errors()->add('content_type', 'Seleccione un catálogo curricular para la nueva selección múltiple.');
            }
            if (($this->input('content_type') === 'multiple_choice'
                || ($field instanceof FieldDefinition && in_array($field->tipo, CurricularFieldOptions::CHOICE_TYPES, true)))
                && $sources === [] && count((array) $this->input('options', $field instanceof FieldDefinition ? $field->opciones : [])) < 2) {
                $validator->errors()->add('options', 'Agregue al menos dos opciones.');
            }
            if ($sources !== [] && $this->input('options') !== null && $this->input('options') !== []) {
                $validator->errors()->add('options', 'Las opciones provienen de las listas curriculares.');
            }
            if ($this->has('show_when') && $this->input('show_when') !== null) {
                $template = $this->route('template');
                $field = $this->route('field');
                $templateId = $template instanceof SyllabusTemplate ? $template->id : (string) $template;
                $error = $field instanceof FieldDefinition && $field->heredado
                    ? 'Un dato automático no puede ocultarse.'
                    : FieldConditions::error($templateId, (string) $this->input('key'), $this->input('show_when'));
                if ($error !== null) {
                    $validator->errors()->add('show_when', $error);
                }
            }
        }];
    }
}
