<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Domain\PersonName;
use App\Modules\Identity\Domain\PersonNameColumns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CreateCareerTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('createCareerTeacher', User::class);
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
        return [
            'primer_nombre' => ['required', 'string', 'max:45'],
            'segundo_nombre' => ['required', 'string', 'max:45'],
            'primer_apellido' => ['required', 'string', 'max:45'],
            'segundo_apellido' => ['required', 'string', 'max:45'],
            'nombre' => ['prohibited'],
            'correo_electronico' => ['required', 'string', 'email', 'max:255'],
            'password' => ['prohibited'],
            'role_code' => ['prohibited'],
            'career_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'active' => ['prohibited'],
            'activo' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];
        foreach (PersonNameColumns::COLUMNS as $key => $label) {
            $messages["{$key}.required"] = 'El '.mb_strtolower($label).' es obligatorio.';
        }

        return $messages;
    }

    /** @return array{nombre: string, correo_electronico: string} */
    public function teacherData(): array
    {
        return [
            'nombre' => PersonName::normalize(implode(' ', array_map(
                fn (string $key): string => $this->string($key)->toString(),
                array_keys(PersonNameColumns::COLUMNS),
            ))),
            'correo_electronico' => $this->string('correo_electronico')->toString(),
        ];
    }
}
