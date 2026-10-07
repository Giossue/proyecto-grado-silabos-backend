<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\SectionDesigns;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\Rule;

class SaveStructureSectionRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $section = $this->route('section');
        $sectionId = is_string($section) ? $section : null;

        $rules = [
            'title' => [$sectionId === null && $this->filled('design') ? 'nullable' : 'required', 'string', 'max:180'],
            'position' => ['sometimes', 'integer', 'min:1'],
        ];
        if ($sectionId === null) {
            $rules['design'] = ['nullable', 'string', Rule::in(app(SectionDesigns::class)->keys())];
            $rules['key'] = [
                $this->filled('design') ? 'nullable' : 'required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100',
                static function (string $attribute, mixed $value, \Closure $fail) use ($template): void {
                    if ($template instanceof SyllabusTemplate && $template->sections()->contains(fn ($existing): bool => $existing->clave === $value)) {
                        $fail('La clave ya está siendo utilizada por otra sección.');
                    }
                },
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['design.in' => 'El diseño oficial elegido no existe.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['design' => 'diseño oficial'];
    }
}
