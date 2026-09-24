<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

class SaveTemplateDocumentRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'array'],
            'fingerprint' => ['required', 'string', 'size:64'],
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'properties' => ['sometimes', 'array', 'max:500'],
            'properties.*' => ['required', 'array:key,label,help,ai_enabled'],
            'properties.*.key' => ['required', 'string', 'distinct', 'max:180'],
            'properties.*.label' => ['required', 'string', 'max:180'],
            'properties.*.help' => ['present', 'nullable', 'string', 'max:2000'],
            'properties.*.ai_enabled' => ['sometimes', 'boolean'],
            'confirm_purge' => ['nullable', 'boolean'],
            'page_orientation' => ['sometimes', 'nullable', 'in:portrait,landscape'],
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
}
