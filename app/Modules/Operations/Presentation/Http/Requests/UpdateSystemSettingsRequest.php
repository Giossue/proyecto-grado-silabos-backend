<?php

namespace App\Modules\Operations\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-system-settings');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // IA y Biblioteca piden la contraseña: guardan una credencial o direcciones externas.
        $password = ['required', 'string', 'current_password:web'];

        return match ((string) $this->route('section')) {
            'notificaciones' => [
                'emails' => ['required', 'boolean'],
                'digest_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            ],
            'ia' => [
                'current_password' => $password,
                'driver' => ['required', Rule::in(['disabled', 'deepseek', 'openai', 'anthropic'])],
                'base_url' => ['nullable', 'required_unless:driver,disabled', 'string', 'max:255', 'url:https'],
                'model' => ['nullable', 'required_unless:driver,disabled', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
                'api_key' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/'],
                'clear_api_key' => ['sometimes', 'boolean'],
                'connect_timeout' => ['required', 'integer', 'between:1,30'],
                'timeout' => ['required', 'integer', 'between:5,300'],
            ],
            'biblioteca' => [
                'current_password' => $password,
                'driver' => ['required', Rule::in(['disabled', 'koha'])],
                'base_url' => ['nullable', 'required_if:driver,koha', 'string', 'max:255', 'url:http,https'],
            ],
            default => [],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Escriba su contraseña actual para guardar.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'digest_time.regex' => 'La hora debe tener el formato HH:MM.',
            'base_url.required_if' => 'Escriba la dirección del catálogo Koha.',
            'base_url.required_unless' => 'Escriba la dirección del proveedor de IA.',
            'model.required_unless' => 'Escriba el modelo de IA.',
            'api_key.regex' => 'La clave no puede tener espacios.',
        ];
    }

    /** @return array<string, mixed> campo de SystemSettings => valor */
    public function settings(): array
    {
        $section = (string) $this->route('section');
        if ($section === 'notificaciones') {
            return [
                'notifications.emails' => $this->boolean('emails'),
                'notifications.digest_time' => $this->string('digest_time')->toString(),
            ];
        }
        if ($section === 'biblioteca') {
            return [
                'library.driver' => $this->string('driver')->toString(),
                'library.base_url' => $this->filled('base_url') ? rtrim($this->string('base_url')->toString(), '/') : null,
            ];
        }
        $values = [
            'ai.driver' => $this->string('driver')->toString(),
            'ai.base_url' => $this->filled('base_url') ? rtrim($this->string('base_url')->toString(), '/') : null,
            'ai.model' => $this->filled('model') ? $this->string('model')->toString() : null,
            'ai.connect_timeout' => $this->integer('connect_timeout'),
            'ai.timeout' => $this->integer('timeout'),
        ];
        if ($this->boolean('clear_api_key')) {
            $values['ai.api_key'] = null;
        } elseif ($this->filled('api_key')) {
            $values['ai.api_key'] = $this->string('api_key')->toString();
        }

        return $values;
    }
}
