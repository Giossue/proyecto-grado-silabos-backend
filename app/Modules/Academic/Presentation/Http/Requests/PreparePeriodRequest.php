<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreparePeriodRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $subjects = $this->input('subjects');
        if (! is_array($subjects)) {
            return;
        }

        $this->merge([
            'subjects' => collect($subjects)
                ->filter(fn (mixed $subject): bool => is_array($subject))
                ->map(function (array $subject): array {
                    $parallels = is_array($subject['parallels'] ?? null)
                        ? $subject['parallels']
                        : [];

                    return [
                        'id' => $subject['id'] ?? null,
                        'parallels' => collect($parallels)
                            ->filter(fn (mixed $parallel): bool => is_array($parallel))
                            ->map(fn (array $parallel): array => [
                                'code' => is_string($parallel['code'] ?? null)
                                    ? trim($parallel['code'])
                                    : '',
                                'shift' => $parallel['shift'] ?? null,
                                'teacher_user_id' => $parallel['teacher_user_id'] ?? null,
                            ])
                            ->filter(fn (array $parallel): bool => $parallel['code'] !== '')
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all(),
        ]);
    }

    public function authorize(): bool
    {
        $activeRole = app(ActiveRole::class)->resolve($this);

        return $this->user()?->activo === true
            && $activeRole !== null
            && AcademicStructurePermissions::mayCreate($activeRole, 'programacion_asignatura')
            && (! $this->includesTeacher()
                || AcademicStructurePermissions::mayCreate($activeRole, 'asignacion_docente'));
    }

    private function includesTeacher(): bool
    {
        $subjects = $this->input('subjects');
        if (! is_array($subjects)) {
            return false;
        }

        foreach ($subjects as $subject) {
            if (! is_array($subject) || ! is_array($subject['parallels'] ?? null)) {
                continue;
            }
            foreach ($subject['parallels'] as $parallel) {
                if (is_array($parallel) && ! empty($parallel['teacher_user_id'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('subjects', []) as $index => $subject) {
                $codes = array_map(
                    fn (mixed $parallel): string => mb_strtoupper(is_array($parallel) && is_string($parallel['code'] ?? null) ? $parallel['code'] : ''),
                    is_array($subject) && is_array($subject['parallels'] ?? null) ? $subject['parallels'] : [],
                );
                if (count($codes) !== count(array_unique($codes))) {
                    $validator->errors()->add("subjects.{$index}.parallels", 'Hay paralelos repetidos en la misma materia.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'subjects.*.parallels.*.code' => 'paralelo',
            'subjects.*.parallels.*.shift' => 'jornada',
            'subjects.*.parallels.*.teacher_user_id' => 'docente',
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period_id' => [
                'required',
                'uuid',
                Rule::exists('periodos_academicos', 'periodo_academico_id'),
            ],
            'subjects' => ['required', 'array', 'min:1', 'max:200'],
            'subjects.*.id' => ['required', 'uuid', 'distinct'],
            'subjects.*.parallels' => ['required', 'array', 'min:1', 'max:50'],
            'subjects.*.parallels.*.code' => ['required', 'string', 'max:30'],
            'subjects.*.parallels.*.shift' => ['required', 'string', Rule::in(Parallel::SHIFTS)],
            'subjects.*.parallels.*.teacher_user_id' => ['nullable', 'uuid'],
        ];
    }
}
