<?php

namespace App\Modules\Documents\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Foundation\Http\FormRequest;

class SignRevisionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $revision = $this->route('revision');

        return $revision instanceof SyllabusRevision && $this->user()?->can('view', $revision) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'signed_pdf' => ['required', 'file', 'mimetypes:application/pdf', 'max:40960'],
            'base_sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'signed_pdf.required' => 'Adjunte el PDF firmado.',
            'signed_pdf.mimetypes' => 'El archivo firmado debe ser un PDF.',
            'signed_pdf.max' => 'El PDF firmado supera el tamaño permitido.',
        ];
    }
}
