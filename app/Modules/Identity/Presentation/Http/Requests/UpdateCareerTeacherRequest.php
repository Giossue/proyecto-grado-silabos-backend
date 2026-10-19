<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Domain\PersonName;
use Closure;
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
            'nombre' => ['required', 'string', 'max:180', fn (string $attribute, mixed $value, Closure $fail) => ! is_string($value)
                || ! $target instanceof User
                || ! $target->hasVerifiedEmail()
                || PersonName::normalize($value) === PersonName::normalize($target->nombre)
                ? null
                : $fail('La cuenta ya fue activada y su nombre no se puede cambiar.'), fn (string $attribute, mixed $value, Closure $fail) => ! is_string($value)
                || PersonName::hasFourParts($value)
                || ($target instanceof User && PersonName::normalize($value) === PersonName::normalize($target->nombre))
                ? null
                : $fail('Escriba el primer y segundo nombre y el primer y segundo apellido.')],
            'correo_electronico' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('usuarios', 'correo_electronico')->ignore($target instanceof User ? $target->id : null, 'usuario_id'),
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
