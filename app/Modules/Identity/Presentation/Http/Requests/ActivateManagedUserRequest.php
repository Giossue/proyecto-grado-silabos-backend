<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class ActivateManagedUserRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
            'password' => [...$this->passwordRules(), 'confirmed'],
        ];
    }
}
