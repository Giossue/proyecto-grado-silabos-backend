<?php

namespace App\Modules\Operations\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManageScreenHelpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-help') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $screen = ['required', 'string', 'max:160', 'regex:/^\/[a-z0-9\/:_-]*$/'];
        if ($this->isMethod('PUT')) {
            return [
                'screen' => $screen,
                'title' => ['required', 'string', 'max:120'],
                'content' => ['required', 'string', 'max:4000'],
                'active' => ['required', 'boolean'],
            ];
        }
        if ($this->isMethod('DELETE')) {
            return ['screen' => $screen];
        }

        return [];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['screen.regex' => 'La pantalla no es válida.'];
    }
}
