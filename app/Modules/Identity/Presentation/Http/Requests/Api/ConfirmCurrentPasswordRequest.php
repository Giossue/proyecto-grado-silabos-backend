<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmCurrentPasswordRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'current_password:web']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['current_password.current_password' => 'La contraseña actual no es correcta.'];
    }
}
