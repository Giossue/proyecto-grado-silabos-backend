<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CurricularUnit;
use App\Modules\Academic\Domain\CurriculumSystemFields;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCareerAcademicRecordRequest extends FormRequest
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
        $activeRole = app(ActiveRole::class)->resolve($this);

        return is_string($entity)
            && $this->user()?->activo === true
            && $activeRole instanceof RoleAssignment
            && AcademicStructurePermissions::mayUpdate($activeRole, $entity)
            && $this->recordBelongsToCareer($entity, $this->recordId(), $activeRole->carrera_id);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return match ($this->route('entity')) {
            'asignatura' => $this->subjectRules(),
            default => [],
        };
    }

    private function recordBelongsToCareer(string $entity, string $recordId, ?string $careerId): bool
    {
        if ($careerId === null) {
            return false;
        }

        return match ($entity) {
            'asignatura' => Subject::query()->whereKey($recordId)->where('carrera_id', $careerId)->exists(),
            default => false,
        };
    }

    /** @return array<string, list<mixed>> */
    private function subjectRules(): array
    {
        $careerId = $this->subjectCareerId();
        $rules = [
            'code' => [
                'required',
                'string',
                'max:80',
                Rule::unique('asignaturas', 'codigo_asignatura')
                    ->where('carrera_id', $careerId)
                    ->ignore($this->recordId(), 'asignatura_id'),
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
        ];

        return $rules;
    }

    private function subjectCareerId(): string
    {
        return (string) Subject::query()->whereKey($this->recordId())->value('carrera_id');
    }

    private function recordId(): string
    {
        return is_string($this->route('record')) ? $this->route('record') : '';
    }
}
