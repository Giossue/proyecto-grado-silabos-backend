<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return match ($this->route('entity')) {
            'facultad' => [
                ...$this->namedCatalogRules('facultades', 180),
                'campus' => ['required', Rule::enum(CampusLocation::class)],
                'logo' => ['nullable', ...InstitutionalLogos::rules(InstitutionalLogos::FACULTY)],
            ],
            'carrera' => [
                'faculty_id' => ['required', 'uuid', Rule::exists('facultades', 'id')],
                'modality' => ['required', 'string', Rule::in(StudyModality::values())],
                ...$this->namedCatalogRules('carreras', 180),
            ],
            'periodo' => [
                'code' => [
                    'required',
                    'string',
                    'max:40',
                    Rule::unique('periodos_academicos', 'codigo_periodo_academico')->ignore($this->recordId()),
                ],
                'starts_on' => ['required', 'date'],
                'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
                'teaching_weeks' => ['required', 'integer', 'min:1', 'max:52'],
            ],
            default => [],
        };
    }

    /** @return array<string, list<mixed>> */
    private function namedCatalogRules(string $table, int $nameLength): array
    {
        return [
            'code' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique($table, match ($table) {
                    'facultades' => 'codigo_facultad',
                    'carreras' => 'codigo_carrera',
                    default => throw new \LogicException('Catálogo académico no admitido.'),
                })->ignore($this->recordId()),
            ],
            'nombre' => ['required', 'string', "max:{$nameLength}"],
        ];
    }

    private function recordId(): string
    {
        $record = $this->route('record');

        return is_string($record) ? $record : '';
    }
}
