<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDraftFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $syllabus = $this->route('syllabus');

        return $syllabus instanceof Syllabus && $this->user()?->can('edit', $syllabus) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version_bloqueo' => ['required', 'integer', 'min:0'],
            'value' => ['present', 'nullable', 'array', function (string $attribute, mixed $value): void {
                if ($value !== null) {
                    FieldDocument::normalize($value, $attribute);
                }
            }],
        ];
    }

    /**
     * @return array{version_bloqueo: int, value?: mixed}
     */
    public function draftData(): array
    {
        $payload = ['version_bloqueo' => $this->integer('version_bloqueo')];
        if ($this->exists('value')) {
            $payload['value'] = $this->input('value');
        }

        return $payload;
    }
}
