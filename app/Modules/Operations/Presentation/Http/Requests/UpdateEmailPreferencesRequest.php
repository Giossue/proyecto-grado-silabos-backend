<?php

namespace App\Modules\Operations\Presentation\Http\Requests;

use App\Models\User;
use App\Modules\Operations\Application\EmailNoticeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEmailPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['preferences' => 'avisos por correo', 'preferences.*' => 'aviso por correo'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            $allowed = $user instanceof User ? array_keys(app(EmailNoticeCatalog::class)->typesFor($user)) : [];
            $preferences = $this->input('preferences');
            if (is_array($preferences) && array_diff(array_keys($preferences), $allowed) !== []) {
                $validator->errors()->add('preferences', 'Hay avisos que no corresponden a sus roles.');
            }
        }];
    }

    /** @return array<string, bool> */
    public function preferences(): array
    {
        $preferences = (array) $this->input('preferences', []);

        return array_map(fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN), $preferences);
    }
}
