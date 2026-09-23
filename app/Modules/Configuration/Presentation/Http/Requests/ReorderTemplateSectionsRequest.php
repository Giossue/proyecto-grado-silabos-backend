<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;

class ReorderTemplateSectionsRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $template = $template instanceof SyllabusTemplate ? $template : null;

        return [
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => [
                'required',
                'uuid',
                'distinct',
                static function (string $attribute, mixed $value, \Closure $fail) use ($template): void {
                    if (! is_string($value) || $template?->section($value) === null) {
                        $fail('La sección seleccionada no pertenece a la plantilla.');
                    }
                },
            ],
        ];
    }
}
