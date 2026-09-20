<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

class UpdateSourceContentRequest extends ManageAcademicSourceRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'document' => ['required_without:content', 'array'],
            'document.type' => ['required_with:document', 'in:doc'],
            'document.schemaVersion' => ['nullable', 'integer', 'in:1'],
            'document.content' => ['required_with:document', 'array', 'max:20000'],
            'content' => ['required_without:document', 'nullable', 'string', 'max:100000'],
        ];
    }
}
