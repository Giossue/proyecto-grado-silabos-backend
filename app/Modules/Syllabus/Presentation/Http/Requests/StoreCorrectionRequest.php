<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): Response
    {
        $revision = $this->route('revision');
        if (! $revision instanceof SyllabusRevision) {
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
