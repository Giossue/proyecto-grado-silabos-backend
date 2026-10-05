<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\FieldConditions;
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
                    ->ignore($fieldId, 'definicion_campo_id'),
            ],
            'label' => ['nullable', 'string', 'max:180'],
            'help' => ['nullable', 'string', 'max:2000'],
            'content_type' => ['required', Rule::in($types)],
            'inherited' => ['nullable', 'boolean'],
            'master_source' => ['nullable', 'string', Rule::in([
                'asignaturas',
                'fichas_asignatura.descripcion',
                'fichas_asignatura.objetivo_general',
                'fichas_asignatura.objetivos_especificos',
                'fichas_asignatura.resultados_aprendizaje',
                'fichas_asignatura.habilidades_blandas',
                'fichas_asignatura.herramientas_tic',
                'fichas_asignatura.relacion_perfil_egreso',
                'fichas_asignatura.bibliografia_basica',
                'fichas_asignatura.bibliografia_complementaria',
            ])],
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
            if ($this->has('master_source') && $this->input('master_source') !== ($field instanceof FieldDefinition ? $field->origen_maestro : null)) {
                $validator->errors()->add('master_source', 'El origen automático de este campo no se puede cambiar.');
            }
            if ($this->has('inherited') && $this->boolean('inherited') !== ($field instanceof FieldDefinition && $field->heredado)) {
                $validator->errors()->add('inherited', 'La herencia de este campo no se puede cambiar.');
            }
            if ($this->has('teacher_editable') && $this->boolean('teacher_editable') !== ($field instanceof FieldDefinition ? $field->editable_docente : true)) {
                $validator->errors()->add('teacher_editable', 'La edición docente de este campo no se puede cambiar.');
            }
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
