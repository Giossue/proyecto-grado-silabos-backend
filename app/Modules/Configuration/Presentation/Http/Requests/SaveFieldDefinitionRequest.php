<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\SyllabusTemplateCatalog;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveFieldDefinitionRequest extends ManageTemplatesRequest
{
    private const NEW_FORMS = ['free_text', 'template'];

    private const SAVED_FORMS = ['text', 'fixed', 'boolean', 'multiple_choice', 'institutional'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : $template;
        $field = $this->route('field');
        $fieldId = $field instanceof FieldDefinition ? $field->id : null;
        $types = $fieldId === null ? self::NEW_FORMS : self::SAVED_FORMS;

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
                    ->where('vigente_en_plantilla', true)
                    ->ignore($fieldId, 'definicion_campo_id'),
            ],
            'label' => ['nullable', 'string', 'max:180'],
            'help' => ['nullable', 'string', 'max:2000'],
            'content_type' => ['required', Rule::in($types)],
            'from_profile' => ['nullable', 'boolean'],
            'preset' => ['nullable', 'string', Rule::in(app(SyllabusTemplateCatalog::class)->keys())],
            'initial_content' => ['sometimes', 'nullable', 'array'],
            'teacher_editable' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['required', 'array:value,label'],
            'options.*.value' => ['required', 'string', 'max:120', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:180'],
            'page_orientation' => ['sometimes', 'nullable', 'in:portrait,landscape'],
            'show_when' => ['sometimes', 'nullable', 'array:key,value'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from_profile' => 'se llena desde la ficha', 'preset' => 'plantilla de campo', 'initial_content' => 'contenido inicial'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'content_type.in' => 'Elija un tipo de campo de la lista.',
            'page_orientation.in' => 'Elija hoja vertical u horizontal.',
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $field = $this->route('field');
            if (($this->input('content_type') === 'multiple_choice'
                || ($field instanceof FieldDefinition && in_array($field->tipo, ['seleccion_unica', 'seleccion_multiple'], true)))
                && count((array) $this->input('options', $field instanceof FieldDefinition ? $field->opciones : [])) < 2) {
                $validator->errors()->add('options', 'Agregue al menos dos opciones.');
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
