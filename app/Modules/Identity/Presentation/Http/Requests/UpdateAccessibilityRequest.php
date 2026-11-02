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
            'wide_spacing' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'high_contrast' => 'alto contraste',
            'text_size' => 'tamaño del texto',
            'no_motion' => 'sin animaciones',
            'wide_spacing' => 'texto espaciado',
        ];
    }
}
