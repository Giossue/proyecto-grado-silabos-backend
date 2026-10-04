<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\RoleCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManagedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        if (! $target instanceof User || $this->user()?->can('updateProfileData', $target) !== true) {
            return false;
        }

        $touchesGovernance = $this->has('active') || $this->has('role_code');

        return ! $touchesGovernance || $this->user()->can('update', $target);
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
                Rule::unique('usuarios', 'correo_electronico')->ignore(
                    $target instanceof User ? $target->id : null,
                ),
            ],
            'active' => ['sometimes', 'required', 'boolean'],
            'role_code' => ['sometimes', 'required', Rule::enum(RoleCode::class)],
            'career_id' => [
                'exclude_without:role_code',
                'nullable',
                'required_unless:role_code,'.RoleCode::Administrator->value.','.RoleCode::Librarian->value.','.RoleCode::Dean->value,
                'uuid',
                Rule::exists('carreras', 'id')->where('carrera_activa', true),
            ],
            'faculty_id' => [
                'exclude_without:role_code',
                'nullable',
                'required_if:role_code,'.RoleCode::Dean->value,
                'uuid',
                Rule::exists('facultades', 'id')->where('facultad_activa', true),
            ],
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
        $data = [
            'nombre' => $this->string('nombre')->toString(),
            'correo_electronico' => $this->string('correo_electronico')->toString(),
        ];

        return $data;
    }
}
