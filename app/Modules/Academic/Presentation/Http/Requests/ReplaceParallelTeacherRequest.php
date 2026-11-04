<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceParallelTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->activo === true
            && AcademicStructurePermissions::mayCreate(app(ActiveRole::class)->resolve($this), 'asignacion_docente');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'confirm_purge' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['user_id' => 'docente'];
    }
}
