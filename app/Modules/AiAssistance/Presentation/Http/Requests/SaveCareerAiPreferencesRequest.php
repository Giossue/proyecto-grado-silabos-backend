<?php

namespace App\Modules\AiAssistance\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCareerAiPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'max:200'],
            'preferences.*.field_id' => ['required', 'uuid', 'distinct'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
