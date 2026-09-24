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
            'properties.*' => ['required', 'array:key,label,help,ai_enabled,rules'],
            'properties.*.key' => ['required', 'string', 'distinct', 'max:180'],
            'properties.*.label' => ['required', 'string', 'max:180'],
            'properties.*.help' => ['present', 'nullable', 'string', 'max:2000'],
            'properties.*.ai_enabled' => ['sometimes', 'boolean'],
            'properties.*.rules' => ['sometimes', 'nullable', 'array:required,max_length,min_rows,max_rows'],
            'properties.*.rules.required' => ['sometimes', 'boolean'],
            'properties.*.rules.max_length' => ['nullable', 'integer'],
            'properties.*.rules.min_rows' => ['nullable', 'integer'],
            'properties.*.rules.max_rows' => ['nullable', 'integer'],
            'confirm_purge' => ['nullable', 'boolean'],
        ];
    }
}
