<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class RequestCurricularImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-career-academics') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'mimes:pdf,xlsx', 'max:20480'],
            'instruction' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['files' => 'documentos', 'files.*' => 'archivo', 'instruction' => 'indicación para la importación'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $files = $this->file('files', []);
            if (! is_array($files)) {
                return;
            }
            $size = 0;
            foreach ($files as $file) {
                $size += $file->getSize();
            }
            if ($size > 30 * 1024 * 1024) {
                $validator->errors()->add('files', 'Los documentos juntos no pueden superar 30 MB. Sepárelos en varias importaciones.');
            }
        }];
    }
}
