<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConvocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Convocation::class) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'process_id' => ['required', 'uuid', Rule::exists('convocatorias_universidad', 'id')->where('estado_convocatoria_universidad', 'abierto')],
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['required', 'uuid', 'distinct', 'exists:fuentes_academicas,id'],
        ];
    }

    /** @return array{process_id: string, source_ids: list<string>} */
    public function convocationData(): array
    {
        $sourceIds = $this->input('source_ids', []);

        return [
            'process_id' => $this->string('process_id')->toString(),
            'source_ids' => is_array($sourceIds) ? array_values(array_filter($sourceIds, 'is_string')) : [],
        ];
    }
}
