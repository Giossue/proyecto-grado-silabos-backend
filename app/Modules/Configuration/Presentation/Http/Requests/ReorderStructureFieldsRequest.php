<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

class ReorderStructureFieldsRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'field_ids' => ['required', 'array', 'min:1'],
            'field_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
