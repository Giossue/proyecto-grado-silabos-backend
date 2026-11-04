<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ViewCoordinationHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewAny', Syllabus::class) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'period' => ['nullable', 'uuid', Rule::exists('periodos_academicos', 'periodo_academico_id')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['q' => 'búsqueda', 'period' => 'período'];
    }
}
