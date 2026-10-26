<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewFichaWorkbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:xlsx', 'max:20480']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['file.required' => 'Elija el Excel que completó.', 'file.mimes' => 'Use la plantilla Excel (.xlsx) descargada desde el sitio.', 'file.max' => 'El Excel no puede superar los 20 MB.'];
    }
}
