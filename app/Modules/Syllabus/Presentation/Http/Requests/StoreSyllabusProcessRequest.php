<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Syllabus\Application\ProcessDates;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSyllabusProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SyllabusProcess::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'starts_at' => ProcessDates::startOfDay($this->input('starts_at')),
            'due_at' => ProcessDates::endOfDay($this->input('due_at')),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period_id' => ['required', 'uuid', Rule::exists('periodos_academicos', 'periodo_academico_id')],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'due_at' => ['required', 'date', 'after:now', 'after:starts_at'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $periodId = $this->input('period_id');
            $period = is_string($periodId) ? AcademicPeriod::query()->find($periodId) : null;
            if ($period === null) {
                return;
            }
            $end = $period->fecha_fin->endOfDay();
            foreach (['starts_at', 'due_at'] as $key) {
                $value = $this->input($key);
                if (is_string($value) && strtotime($value) !== false && strtotime($value) > $end->getTimestamp()) {
                    $validator->errors()->add($key, 'La fecha no puede pasar del fin del período ('.$period->fecha_fin->format('d/m/Y').').');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_at.after_or_equal' => 'El inicio no puede ser anterior a hoy.',
            'due_at.after' => 'La fecha de entrega debe ser posterior al inicio y a hoy.',
        ];
    }

    /** @return array{period_id: string, starts_at: string, due_at: string} */
    public function processData(): array
    {
        return [
            'period_id' => $this->string('period_id')->toString(),
            'starts_at' => $this->string('starts_at')->toString(),
            'due_at' => $this->string('due_at')->toString(),
        ];
    }
}
