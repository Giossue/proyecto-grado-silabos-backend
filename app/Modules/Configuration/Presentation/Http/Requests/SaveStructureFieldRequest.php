<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;

class SaveStructureFieldRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $registry = app(FieldTypeRegistry::class);
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : (string) $template;
        $field = $this->route('field');
        $creating = ! $field instanceof FieldDefinition;
        $typeKey = $creating ? $this->input('type') : $field->fieldType()->key();

        $rules = [
            'label' => ['nullable', 'string', 'max:180'],
            'help' => ['nullable', 'string', 'max:2000'],
            'type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in($registry->keys())],
            'config' => ['sometimes', 'nullable', 'array'],
        ];
        if ($creating) {
            $rules['position'] = ['sometimes', 'integer', 'min:1'];
            $rules['key'] = [
                'required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100',
                Rule::unique('definiciones_campo', 'clave_definicion_campo')
                    ->where('plantilla_id', $templateId)
                    ->where('vigente_en_plantilla', true),
            ];
        }
        if (is_string($typeKey) && $registry->has($typeKey)) {
            foreach ($registry->get($typeKey)->configRules() as $key => $keyRules) {
                $rules['config.'.$key] = $keyRules;
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['type.in' => 'Elija un tipo de campo de la lista.'];
    }
}
