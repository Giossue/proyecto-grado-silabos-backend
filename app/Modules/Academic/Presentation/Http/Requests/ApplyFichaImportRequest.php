<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyFichaImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'subjects' => ['required', 'array', 'min:1', 'max:100'], 'subjects.*' => ['uuid'],
            'fields' => ['sometimes', 'array'], 'fields.*' => ['array'], 'fields.*.*' => ['string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['subjects' => 'materias', 'subjects.*' => 'materia', 'fields' => 'campos', 'fields.*' => 'campos de la materia', 'fields.*.*' => 'campo'];
    }
}
