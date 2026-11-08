<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ViewProcessReportRequest extends FormRequest
{
    /** Los reportes institucionales los ve Administración, como la auditoría. */
    public function authorize(): bool
    {
        return $this->user()?->can('view-audit') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['process' => ['nullable', 'uuid']];
    }
}
