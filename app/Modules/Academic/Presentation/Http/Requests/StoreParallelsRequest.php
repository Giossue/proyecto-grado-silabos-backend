<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParallelsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $codes = $this->input('codes');
        if (! is_array($codes)) {
            return;
        }

        $this->merge([
            'codes' => collect($codes)
                ->filter(fn (mixed $code): bool => is_string($code))
                ->map(fn (string $code) => trim($code))
                ->filter(fn (string $code) => $code !== '')
                ->values()
                ->all(),
        ]);
    }

    public function authorize(): bool
    {
        $activeRole = app(ActiveRole::class)->resolve($this);

        return $this->user()?->activo === true
            && $activeRole !== null
            && AcademicStructurePermissions::mayCreate($activeRole, 'paralelo');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $careerId = app(ActiveRole::class)->resolve($this)?->carrera_id;

        return [
            'subject_id' => [
                'required',
                'uuid',
                Rule::exists('asignaturas', 'asignatura_id')->where(fn ($query) => $query
                    ->where('asignatura_activa', true)
                    ->where('carrera_id', $careerId)),
            ],
            'codes' => ['required', 'array', 'min:1', 'max:50'],
            'codes.*' => ['required', 'string', 'max:30', 'distinct'],
            'shift' => ['required', 'string', Rule::in(Parallel::SHIFTS)],
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('usuarios', 'usuario_id')->where(fn ($query) => $query
                    ->where('usuario_activo', true)
                    ->whereNotNull('correo_verificado_en')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Seleccione el docente del paralelo.',
            'user_id.exists' => 'Elija un docente activo con el correo verificado.',
        ];
    }
}
