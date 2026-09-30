<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Foundation\Http\FormRequest;

class CopySyllabusContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $syllabus = $this->route('syllabus');
        $source = $this->route('source');
        if (! $syllabus instanceof Syllabus || ! $source instanceof Syllabus) {
            return false;
        }

        $ability = $syllabus->estado === 'sin_iniciar' ? 'start' : 'edit';

        return $this->user()?->can($ability, $syllabus) === true && $this->user()->can('view', $source);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'version_bloqueo' => ['required', 'integer', 'min:0'],
        ];
    }
}
