<?php

namespace App\Modules\Operations\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowScreenHelpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->activo === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['screen' => ['required', 'string', 'max:160', 'regex:/^\/[a-z0-9\/:_-]*$/']];
    }
}
