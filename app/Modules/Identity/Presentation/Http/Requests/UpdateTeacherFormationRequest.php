<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeacherFormationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->activo === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['formacion_experiencia' => ['required', 'string', 'filled']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['formacion_experiencia' => 'formación y experiencia'];
    }
}
