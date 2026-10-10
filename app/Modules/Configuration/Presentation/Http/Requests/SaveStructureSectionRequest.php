<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;

class SaveStructureSectionRequest extends ManageTemplatesRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $template = $this->route('template');
        $section = $this->route('section');
        $sectionId = is_string($section) ? $section : null;

        $rules = [
            'title' => ['required', 'string', 'max:180'],
            'orientation' => ['sometimes', Rule::in(['portrait', 'landscape'])],
            'position' => ['sometimes', 'integer', 'min:1'],
        ];
        if ($sectionId === null) {
            $rules['key'] = [
                'required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100',
                static function (string $attribute, mixed $value, \Closure $fail) use ($template): void {
                    if ($template instanceof SyllabusTemplate && $template->sections()->contains(fn ($existing): bool => $existing->clave === $value)) {
                        $fail('La clave ya está siendo utilizada por otra sección.');
                    }
                },
            ];
        }

        return $rules;
    }
}
