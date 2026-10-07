<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Identity\Presentation\Http\Requests\ImportSpreadsheetRequest;
use Illuminate\Validation\Rule;

class ImportScheduledAssignmentsRequest extends ImportSpreadsheetRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'period_id' => ['required', 'uuid', Rule::exists('periodos_academicos', 'periodo_academico_id')],
        ];
    }
}
