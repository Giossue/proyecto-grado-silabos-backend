<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccessibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->activo === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'high_contrast' => ['required', 'boolean'],
            'text_size' => ['required', 'string', 'in:100,115,130,150'],
            'no_motion' => ['required', 'boolean'],
            'underline_links' => ['required', 'boolean'],
            'wide_spacing' => ['required', 'boolean'],
        ];
    }
}
