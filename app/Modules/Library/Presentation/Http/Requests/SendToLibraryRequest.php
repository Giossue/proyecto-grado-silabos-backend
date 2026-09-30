<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Foundation\Http\FormRequest;

class SendToLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $revision = $this->route('revision');

        return $revision instanceof SyllabusRevision && $this->user()?->can('view', $revision) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
