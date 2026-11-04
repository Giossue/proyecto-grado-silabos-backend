<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Application\ProcessDates;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Foundation\Http\FormRequest;

class ExtendProcessDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $process = $this->route('process');

        return $process instanceof SyllabusProcess
            && $this->user()?->can('extendDeadline', $process) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['due_at' => ProcessDates::endOfDay($this->input('due_at'))]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'stage' => ['prohibited'],
            'due_at' => ['required', 'date'],
            'confirmar_rehacer' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'stage.prohibited' => 'La prórroga solo modifica la fecha límite de entrega.',
        ];
    }
}
