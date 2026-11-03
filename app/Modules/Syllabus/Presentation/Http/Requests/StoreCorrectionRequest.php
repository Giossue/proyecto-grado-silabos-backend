<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): Response
    {
        $revision = $this->route('revision');
        // Ruta de Coordinación: Biblioteca pide corrección con sus motivos enumerados en su propia ruta.
        if (! $revision instanceof SyllabusRevision || ! app(ActiveRole::class)->hasRole($this, RoleCode::Coordinator)) {
            return Response::deny();
        }

        return Gate::forUser($this->user())->inspect('decide', $revision);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'justification' => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }
}
