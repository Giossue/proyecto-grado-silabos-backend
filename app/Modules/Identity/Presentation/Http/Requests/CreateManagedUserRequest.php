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

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'primer_nombre' => ['required', 'string', 'max:45'],
            'segundo_nombre' => ['required', 'string', 'max:45'],
            'primer_apellido' => ['required', 'string', 'max:45'],
            'segundo_apellido' => ['required', 'string', 'max:45'],
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
        foreach (PersonNameColumns::COLUMNS as $key => $label) {
            $messages["{$key}.required"] = 'El '.mb_strtolower($label).' es obligatorio.';
        }

        return $messages;
    }

    public function fullName(): string
    {
        return PersonName::normalize(implode(' ', array_map(
            fn (string $key): string => $this->string($key)->toString(),
            array_keys(PersonNameColumns::COLUMNS),
        )));
    }
}
