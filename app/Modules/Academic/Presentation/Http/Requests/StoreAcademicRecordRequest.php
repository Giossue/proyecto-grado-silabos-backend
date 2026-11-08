<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Application\AcademicPeriodWeeks;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\CurricularUnit;
use App\Modules\Academic\Domain\CurriculumSystemFields;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Application\ActiveRole;
use App\Support\InstitutionDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAcademicRecordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $libraries = $this->input('catalog_libraries');
        if ($this->route('entity') === 'facultad' && is_string($libraries)) {
            $decoded = json_decode($libraries, true);
            $this->merge(['catalog_libraries' => is_array($decoded) ? $decoded : $libraries]);
        }

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
                'catalog_libraries' => ['sometimes', 'array', 'max:20'],
                'catalog_libraries.*' => ['string', 'distinct', 'regex:/^[A-Za-z0-9_-]{1,20}$/'],
                // El logo puede cargarse después; la convocatoria no se abre sin él.
                'logo' => ['nullable', ...InstitutionalLogos::rules(InstitutionalLogos::FACULTY)],
            ],
            'carrera' => [
                'faculty_id' => ['required', 'uuid', Rule::exists('facultades', 'facultad_id')->where('facultad_activa', true)],
                'modality' => ['required', 'string', Rule::in(StudyModality::values())],
                'code' => ['required', 'string', 'max:80', Rule::unique('carreras', 'codigo_carrera')],
                'nombre' => ['required', 'string', 'max:180'],
                'cycle_count' => ['required', 'integer', 'min:1', 'max:30'],
            ],
            'periodo' => [
                'starts_on' => ['required', 'date'],
                'ends_on' => ['required', 'date'],
                'teaching_weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
            ],
            'asignatura' => $this->subjectRules(),
            'asignacion_docente' => [
                'user_id' => [
                    'required',
                    'uuid',
                    Rule::exists('usuarios', 'usuario_id')->where(fn ($query) => $query
                        ->where('usuario_activo', true)
                        ->whereNotNull('correo_verificado_en')),
                ],
                'parallel_id' => [
                    'required',
                    'uuid',
                    Rule::exists('paralelos', 'paralelo_id')->where(fn ($query) => $query
                        ->whereIn('asignatura_id', Subject::query()
                            ->select('id')
                            ->where('activo', true)
                            ->where('carrera_id', $this->careerId()))),
                ],
            ],
            default => [],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
            'code.unique' => $this->route('entity') === 'asignatura'
                ? 'Ya existe una materia con ese código.'
                : 'Ya existe una carrera con ese código.',
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('entity') !== 'periodo') {
                return;
            }

            $startsOn = InstitutionDate::dateOfValue($this->input('starts_on'));
            $endsOn = InstitutionDate::dateOfValue($this->input('ends_on'));

            if ($endsOn !== null && $startsOn !== null && $endsOn < $startsOn) {
                $validator->errors()->add('ends_on', 'La fecha de fin debe ser igual o posterior a la fecha de inicio.');
            }

            if ($validator->errors()->has('starts_on')
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
            'organization_unit' => ['required', 'string', Rule::in(CurricularUnit::values())],
            'modality' => ['nullable', 'string', Rule::in(StudyModality::values())],
            'creditos' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'horas_totales' => ['required', 'integer', 'min:0', 'max:32767'],
            'horas_ac' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'horas_pae' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'horas_aa' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'generates_syllabus' => ['sometimes', 'boolean'],
        ];

        return $rules;
    }

    private function careerId(): ?string
    {
        return app(ActiveRole::class)->resolve($this)?->carrera_id;
    }
}
