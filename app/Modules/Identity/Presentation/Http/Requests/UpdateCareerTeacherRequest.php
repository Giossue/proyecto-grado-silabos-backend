<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCareerTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && Gate::allows('updateCareerTeacher', $target);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('correo_electronico'))) {
            $this->merge(['correo_electronico' => mb_strtolower(trim($this->input('correo_electronico')))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'nombre' => ['required', 'string', 'max:180'],
            'correo_electronico' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('usuarios', 'correo_electronico')->ignore($target instanceof User ? $target->id : null),
            ],
            'password' => ['prohibited'],
            'role_code' => ['prohibited'],
            'career_id' => ['prohibited'],
            'active' => ['prohibited'],
            'activo' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'correo_electronico.unique' => 'Ya existe otra cuenta con este correo.',
        ];
    }

    /** @return array{nombre: string, correo_electronico: string} */
    public function profileData(): array
    {
        return [
            'nombre' => $this->string('nombre')->toString(),
            'correo_electronico' => $this->string('correo_electronico')->toString(),
        ];
    }
}
