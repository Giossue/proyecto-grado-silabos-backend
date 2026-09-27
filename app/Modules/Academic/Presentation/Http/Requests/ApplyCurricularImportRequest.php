<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCurricularImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-career-academics') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'confirmed' => ['required', 'boolean', 'accepted'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:10000'],
            'items.*' => ['required', 'array:id,subject_id,accept_conflict'],
            'items.*.id' => ['required', 'uuid', 'distinct:strict'],
            'items.*.subject_id' => ['sometimes', 'nullable', 'uuid'],
            'items.*.accept_conflict' => ['sometimes', 'boolean'],
            'confirm_purge' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'expected_fingerprint' => 'referencia de la propuesta consultada',
            'confirmed' => 'confirmación de los datos curriculares',
            'items' => 'datos seleccionados', 'items.*' => 'dato seleccionado',
            'items.*.id' => 'identificador del dato seleccionado',
            'items.*.subject_id' => 'asignatura del dato seleccionado',
            'items.*.accept_conflict' => 'confirmación de la discrepancia entre fuentes',
            'confirm_purge' => 'confirmación del reemplazo de sílabos',
        ];
    }
}
