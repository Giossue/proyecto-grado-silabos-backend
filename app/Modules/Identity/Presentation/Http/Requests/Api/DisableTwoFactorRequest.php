<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DisableTwoFactorRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['current_password.current_password' => 'La contraseña actual no es correcta.'];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Cancelling an unconfirmed setup only needs the password; an active 2FA also needs a second factor.
            $user = $this->user();
            if ($user instanceof User && $user->hasEnabledTwoFactorAuthentication()
                && ! $this->filled('code') && ! $this->filled('recovery_code')) {
                $validator->errors()->add('code', 'Ingrese un código de autenticación o de recuperación.');
            }
        }];
    }
}
