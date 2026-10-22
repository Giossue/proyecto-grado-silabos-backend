<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Syllabus\Application\ProcessDates;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
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
            'starts_at' => ['required', 'date'],
            'due_at' => ['required', 'date'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $today = InstitutionDate::todayOn();
            $startsOn = InstitutionDate::dayOfValue($this->input('starts_at'));
            $dueOn = InstitutionDate::dayOfValue($this->input('due_at'));

            if ($startsOn !== null && $startsOn < $today) {
                $validator->errors()->add('starts_at', 'El inicio no puede ser anterior a hoy.');
            }
            if ($dueOn !== null && $dueOn < $today) {
                $validator->errors()->add('due_at', 'La fecha de entrega debe ser posterior al inicio y a hoy.');
            }
            if ($startsOn !== null && $dueOn !== null && $dueOn < $startsOn) {
                $validator->errors()->add('due_at', 'La fecha de entrega debe ser posterior al inicio y a hoy.');
            }

            $periodId = $this->input('period_id');
            $period = is_string($periodId) ? AcademicPeriod::query()->find($periodId) : null;
            if ($period === null) {
                return;
            }
            $endOn = InstitutionDate::dayOf($period->fecha_fin);
            if ($endOn < $today) {
                $validator->errors()->add('period_id', 'El período académico ya terminó ('.$period->fecha_fin->format('d/m/Y').'). Elija uno vigente.');

                return;
            }
            foreach (['starts_at', 'due_at'] as $key) {
                $value = $this->input($key);
                $on = InstitutionDate::dayOfValue($value);
                if ($on !== null && $on > $endOn) {
                    $validator->errors()->add($key, 'La fecha no puede pasar del fin del período ('.$period->fecha_fin->format('d/m/Y').').');
                }
            }
        }];
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
