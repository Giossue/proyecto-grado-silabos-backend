<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSubjectLearningOutcomesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-career-academics') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'source_name' => ['required', 'string', 'max:240'],
            'source_locator' => ['required', 'string', 'max:500'],
            'outcomes' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'outcomes.*' => ['required', 'array:id,text'],
            'outcomes.*.id' => ['sometimes', 'uuid', 'distinct:strict'],
            'outcomes.*.text' => ['required', 'string', 'max:12000'],
            'confirmed' => ['required', 'boolean', 'accepted'],
            'expected_fingerprint' => ['present', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'confirm_purge' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'source_name' => 'nombre del documento fuente',
            'source_locator' => 'página o apartado de la fuente',
            'outcomes' => 'resultados de aprendizaje',
            'outcomes.*' => 'resultado de aprendizaje',
            'outcomes.*.id' => 'identificador del resultado de aprendizaje',
            'outcomes.*.text' => 'texto del resultado de aprendizaje',
            'confirmed' => 'confirmación de los resultados',
            'expected_fingerprint' => 'referencia de los resultados consultados',
        ];
    }
}
