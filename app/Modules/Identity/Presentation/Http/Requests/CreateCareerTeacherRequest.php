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
        foreach (array_keys(PersonNameColumns::COLUMNS) as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => PersonName::collapse($this->input($key))]);
            }
        }
        if (is_string($this->input('correo_electronico'))) {
            $this->merge(['correo_electronico' => mb_strtolower(trim($this->input('correo_electronico')))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:'.PersonNameColumns::MAX_LENGTH, 'regex:'.PersonName::PART_PATTERN],
            'apellidos' => ['required', 'string', 'max:'.PersonNameColumns::MAX_LENGTH, 'regex:'.PersonName::PART_PATTERN],
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

        return [...$messages, ...PersonNameColumns::messages()];
    }

    /** @return array{nombre: string, correo_electronico: string} */
    public function teacherData(): array
    {
        return [
            'nombre' => PersonNameColumns::fullName($this->string('nombres')->toString(), $this->string('apellidos')->toString()),
            'correo_electronico' => $this->string('correo_electronico')->toString(),
        ];
    }
}
