<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ApproveSyllabusRequest extends FormRequest
{
    public function authorize(): Response
    {
        $revision = $this->route('revision');
        if (! $revision instanceof SyllabusRevision || ! app(ActiveRole::class)->hasRole($this, RoleCode::Coordinator)) {
            return Response::deny();
        }

        return Gate::forUser($this->user())->inspect('decide', $revision);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['signature' => ['nullable', 'array']];
    }
}
