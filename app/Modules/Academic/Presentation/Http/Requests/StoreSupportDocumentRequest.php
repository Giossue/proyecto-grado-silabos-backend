<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Application\SupportDocuments;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupportDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        if (is_string($title)) {
            $this->merge(['title' => trim((string) preg_replace('/\s+/u', ' ', $title))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'file' => ['required', 'file', 'extensions:'.implode(',', SupportDocuments::EXTENSIONS), 'mimes:'.implode(',', SupportDocuments::EXTENSIONS), 'max:'.SupportDocuments::MAX_KILOBYTES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Escriba el nombre del documento.',
            'title.max' => 'El nombre no puede pasar de 180 caracteres.',
            'file.required' => 'Elija el archivo.',
            'file.extensions' => 'Use un PDF, Word o Excel.',
            'file.mimes' => 'Use un PDF, Word o Excel.',
            'file.max' => 'El archivo no puede superar los 20 MB.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title' => 'nombre', 'file' => 'archivo'];
    }
}
