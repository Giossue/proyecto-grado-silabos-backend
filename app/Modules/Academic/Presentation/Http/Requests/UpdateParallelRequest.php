<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParallelRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->input('code'))]);
        }
    }

    public function authorize(): bool
    {
        $activeRole = app(ActiveRole::class)->resolve($this);

        return $this->user()?->activo === true
            && AcademicStructurePermissions::mayCreate($activeRole, 'paralelo');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'shift' => ['required', 'string', Rule::in(Parallel::SHIFTS)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'código de paralelo', 'shift' => 'jornada'];
    }
}
