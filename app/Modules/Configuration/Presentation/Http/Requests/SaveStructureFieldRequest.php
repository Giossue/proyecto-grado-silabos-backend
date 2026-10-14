<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;

class SaveStructureFieldRequest extends ManageTemplatesRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $template = $this->route('template');
        $templateId = $template instanceof SyllabusTemplate ? $template->id : (string) $template;
        $creating = ! $this->route('field') instanceof FieldDefinition;
        $rules = [
            'label' => ['nullable', 'string', 'max:180'],
            'help' => ['nullable', 'string', 'max:2000'],
            'quien_llena' => [$creating ? 'required' : 'sometimes', Rule::in(FieldDefinition::RESPONSIBLES)],
            'content' => [$creating ? 'required' : 'sometimes', 'array', function (string $attribute, mixed $value): void {
                FieldDocument::normalize($value, $attribute);
            }],
            'type' => ['prohibited'],
            'config' => ['prohibited'],
        ];
        if ($creating) {
            $rules['position'] = ['sometimes', 'integer', 'min:1'];
            $rules['key'] = ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100', Rule::unique('campos_plantilla', 'clave_campo_plantilla')->where('plantilla_id', $templateId)->where('vigente_en_plantilla', true)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['quien_llena' => 'quién lo llena', 'content' => 'contenido', 'label' => 'título', 'help' => 'ayuda', 'key' => 'clave', 'type' => 'tipo retirado', 'config' => 'configuración retirada'];
    }
}
