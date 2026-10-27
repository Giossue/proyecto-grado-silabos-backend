<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Application\AcademicPeriodWeeks;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAcademicRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = $this->route('entity');

        return is_string($entity)
            && $this->user()?->activo === true
            && AcademicStructurePermissions::mayUpdate(
                app(ActiveRole::class)->resolve($this),
                $entity,
            );
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = match ($this->route('entity')) {
            'facultad' => [
                'nombre' => ['required', 'string', 'max:180'],
                'campus' => ['required', Rule::enum(CampusLocation::class)],
                'logo' => [
                    $this->facultyRequiresLogo() ? 'required' : 'nullable',
                    ...InstitutionalLogos::rules(InstitutionalLogos::FACULTY),
                ],
            ],
            'carrera' => [
                'faculty_id' => ['required', 'uuid', Rule::exists('facultades', 'facultad_id')],
                'modality' => ['required', 'string', Rule::in(StudyModality::values())],
                'code' => [
                    'required',
                    'string',
                    'max:80',
                    Rule::unique('carreras', 'codigo_carrera')->ignore($this->recordId(), 'carrera_id'),
                ],
                'nombre' => ['required', 'string', 'max:180'],
            ],
            'periodo' => [
                'starts_on' => ['required', 'date'],
                'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
                'teaching_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            ],
            default => [],
        };

        return [...$rules, 'confirm_purge' => ['nullable', 'boolean']];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('entity') !== 'periodo'
                || $validator->errors()->has('starts_on')
                || $validator->errors()->has('ends_on')) {
                return;
            }

            $weeks = app(AcademicPeriodWeeks::class)->calculate(
                (string) $this->input('starts_on'),
                (string) $this->input('ends_on'),
            );
            if ($weeks > 52) {
                $validator->errors()->add('ends_on', 'El período no puede superar 52 semanas calendario.');
            }
            if ((int) $this->input('teaching_weeks', 0) > $weeks) {
                $validator->errors()->add('teaching_weeks', "Las semanas de clase no pueden superar las {$weeks} semanas entre las fechas.");
            }
        }];
    }

    private function recordId(): string
    {
        $record = $this->route('record');

        return is_string($record) ? $record : '';
    }

    private function facultyRequiresLogo(): bool
    {
        $faculty = Faculty::query()->with('logoObject')->find($this->recordId());

        return $faculty instanceof Faculty
            && ! app(InstitutionalLogos::class)->facultyIsConfigured($faculty);
    }
}
