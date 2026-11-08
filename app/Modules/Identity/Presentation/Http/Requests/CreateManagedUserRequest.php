<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Domain\PersonName;
use App\Modules\Identity\Domain\PersonNameColumns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CreateManagedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (array_keys(PersonNameColumns::COLUMNS) as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => PersonName::collapse($this->input($key))]);
            }
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:'.PersonNameColumns::MAX_LENGTH, 'regex:'.PersonName::PART_PATTERN],
            'apellidos' => ['required', 'string', 'max:'.PersonNameColumns::MAX_LENGTH, 'regex:'.PersonName::PART_PATTERN],
            'nombre' => ['prohibited'],
            'correo_electronico' => ['required', 'string', 'email', 'max:255', Rule::unique('usuarios', 'correo_electronico')],
            'password' => ['prohibited'],
            'role_code' => ['required', Rule::enum(RoleCode::class)],
            'career_id' => [
                'nullable',
                'required_unless:role_code,'.RoleCode::Administrator->value.','.RoleCode::Librarian->value,
                'uuid',
                Rule::exists('carreras', 'carrera_id')->where('carrera_activa', true),
            ],
            'replace_coordinator' => ['sometimes', 'boolean'],
            'faculty_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [
            'correo_electronico.unique' => 'Ya existe una cuenta con este correo.',
            'career_id.required_unless' => 'Seleccione la carrera que limita este rol.',
        ];

        return [...$messages, ...PersonNameColumns::messages()];
    }

    public function fullName(): string
    {
        return PersonNameColumns::fullName($this->string('nombres')->toString(), $this->string('apellidos')->toString());
    }
}
