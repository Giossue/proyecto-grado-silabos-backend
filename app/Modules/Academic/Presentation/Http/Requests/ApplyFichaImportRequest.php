<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

class ApplyFichaImportRequest extends PreviewFichaWorkbookRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [...parent::rules(),
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'file_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'confirm_purge' => ['sometimes', 'boolean'],
            'subjects' => ['required', 'array', 'min:1', 'max:100'], 'subjects.*' => ['uuid', 'distinct'],
            'fields' => ['sometimes', 'array'], 'fields.*' => ['array'], 'fields.*.*' => ['string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['fingerprint' => 'estructura de la plantilla', 'file_hash' => 'archivo revisado', 'subjects' => 'materias', 'subjects.*' => 'materia', 'fields' => 'campos', 'fields.*' => 'campos de la materia', 'fields.*.*' => 'campo'];
    }
}
