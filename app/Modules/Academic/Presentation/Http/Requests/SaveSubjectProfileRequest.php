<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSubjectProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->activo === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'values' => ['present', 'array'],
            'values.*' => ['nullable', 'array'],
            'editable' => ['sometimes', 'array'],
            'editable.*' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['values' => 'datos de la ficha', 'values.*' => 'dato de la ficha', 'editable' => 'campos que edita el docente', 'editable.*' => 'permiso del docente'];
    }
}
