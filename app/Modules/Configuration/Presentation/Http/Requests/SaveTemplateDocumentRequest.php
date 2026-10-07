<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Validator;

class SaveTemplateDocumentRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'array'],
            'fingerprint' => ['required', 'string', 'size:64'],
            'title' => ['sometimes', 'nullable', 'string', 'max:180'],
            'properties' => ['sometimes', 'array', 'max:500'],
            'properties.*' => ['required', 'array:key,label,help,show_when'],
            'properties.*.key' => ['required', 'string', 'distinct', 'max:180'],
            'properties.*.label' => ['present', 'nullable', 'string', 'max:180'],
            'properties.*.help' => ['present', 'nullable', 'string', 'max:2000'],
            'properties.*.show_when' => ['sometimes', 'nullable', 'array:key,value'],
            'confirm_purge' => ['nullable', 'boolean'],
            'page_orientation' => ['sometimes', 'nullable', 'in:portrait,landscape'],
            'compact' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document.required' => 'Escriba el texto fijo antes de guardar.',
            'page_orientation.in' => 'Elija hoja vertical u horizontal.',
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $template = $this->route('template');
            if (! $template instanceof SyllabusTemplate) {
                return;
            }
            foreach ((array) $this->input('properties', []) as $index => $property) {
                if (! is_array($property) || ! array_key_exists('show_when', $property) || ! is_string($property['key'] ?? null)) {
                    continue;
                }
                $error = FieldConditions::error($template->id, $property['key'], $property['show_when']);
                if ($error !== null) {
                    $validator->errors()->add("properties.$index.show_when", $error);
                }
            }
        }];
    }
}
