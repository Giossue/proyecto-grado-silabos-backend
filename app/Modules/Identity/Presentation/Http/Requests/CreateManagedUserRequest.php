<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\RoleCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CreateManagedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:180'],
            'correo_electronico' => ['required', 'string', 'email', 'max:255', Rule::unique('usuarios', 'correo_electronico')],
            'password' => ['prohibited'],
            'role_code' => ['required', Rule::enum(RoleCode::class)],
            'career_id' => [
                'nullable',
                'required_unless:role_code,'.RoleCode::Administrator->value,
                'uuid',
                Rule::exists('carreras', 'id')->where('carrera_activa', true),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'correo_electronico.unique' => 'Ya existe una cuenta con este correo.',
            'career_id.required_unless' => 'Seleccione la carrera que limita este rol.',
        ];
    }
}
