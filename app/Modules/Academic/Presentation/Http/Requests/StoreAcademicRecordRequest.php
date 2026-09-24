<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Application\AcademicPeriodWeeks;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\CurriculumSystemFields;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAcademicRecordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('entity') !== 'asignatura') {
            return;
        }

        $this->merge([
            'horas_totales' => CurriculumSystemFields::totalHours($this->all()),
        ]);
    }

    public function authorize(): bool
    {
        $entity = $this->route('entity');

        return is_string($entity)
            && $this->user()?->activo === true
            && AcademicStructurePermissions::mayCreate(
                app(ActiveRole::class)->resolve($this),
                $entity,
            );
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return match ($this->route('entity')) {
            'facultad' => [
                'nombre' => ['required', 'string', 'max:180'],
                'campus' => ['required', Rule::enum(CampusLocation::class)],
                'logo' => ['required', ...InstitutionalLogos::rules(InstitutionalLogos::FACULTY)],
            ],
            'carrera' => [
                'faculty_id' => ['required', 'uuid', Rule::exists('facultades', 'id')->where('facultad_activa', true)],
                // La modalidad la aprueba el CES por carrera; las programaciones la heredan (I-35).
                'modality' => ['required', 'string', Rule::in(StudyModality::values())],
                'code' => ['required', 'string', 'max:80', Rule::unique('carreras', 'codigo_carrera')],
                'nombre' => ['required', 'string', 'max:180'],
                'cycle_count' => ['required', 'integer', 'min:1', 'max:30'],
            ],
            'periodo' => [
                'starts_on' => ['required', 'date', 'after_or_equal:'.$this->localToday()],
                'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            ],
            'asignatura' => $this->subjectRules(),
            'asignacion_docente' => [
                'user_id' => [
                    'required',
                    'uuid',
                    Rule::exists('usuarios', 'id')->where(fn ($query) => $query
                        ->where('usuario_activo', true)
                        ->whereNotNull('correo_verificado_en')),
                ],
                'parallel_id' => [
                    'required',
                    'uuid',
                    Rule::exists('paralelos', 'id')->where(fn ($query) => $query
                        ->whereIn('programacion_asignatura_id', ScheduledSubject::query()
                            ->select('id')
                            ->where('programacion_asignatura_activa', true)
                            ->whereHas('subject', fn ($subject) => $subject
                                ->where('carrera_id', $this->careerId())))),
                ],
            ],
            default => [],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_on.after_or_equal' => 'La fecha de inicio debe ser hoy o una fecha posterior.',
        ];
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
        }];
    }

    /** @return array<string, list<mixed>> */
    private function subjectRules(): array
    {
        $rules = [
            'code' => [
                'required',
                'string',
                'max:80',
                Rule::unique('asignaturas', 'codigo_asignatura')
                    ->where('carrera_id', $this->careerId()),
            ],
            'nombre' => ['required', 'string', 'max:180'],
            'cycle' => ['required', 'integer', 'min:1', 'max:30'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'organization_unit' => ['required', 'string', 'max:80'],
            // Vacío = la de la carrera; otra modalidad es una excepción de la materia.
            'modality' => ['nullable', 'string', Rule::in(StudyModality::values())],
            'creditos' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'horas_totales' => ['required', 'integer', 'min:0', 'max:32767'],
            'hours_project' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'hours_ap' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'horas_ac' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'horas_pae' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'horas_aa' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'hours_paec' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
        ];

        return $rules;
    }

    private function careerId(): ?string
    {
        return app(ActiveRole::class)->resolve($this)?->carrera_id;
    }

    private function localToday(): string
    {
        return now((string) config('app.display_timezone'))->toDateString();
    }
}
