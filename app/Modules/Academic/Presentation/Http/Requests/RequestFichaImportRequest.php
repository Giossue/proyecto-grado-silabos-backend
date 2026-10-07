<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestFichaImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:6'],
            'files.*' => ['file', 'mimes:xlsx,xls', 'max:20480'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['files' => 'documentos', 'files.*' => 'documento'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'files.required' => 'Elija al menos un documento.',
            'files.max' => 'Puede subir hasta 6 documentos a la vez.',
            'files.*.mimes' => 'Por ahora solo se admiten archivos de Excel.',
            'files.*.max' => 'Cada documento puede pesar hasta 20 MB.',
        ];
    }
}
