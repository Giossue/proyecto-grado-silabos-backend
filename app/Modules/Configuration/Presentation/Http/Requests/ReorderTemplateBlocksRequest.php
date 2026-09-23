<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;

class ReorderTemplateBlocksRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $template = $this->route('template');
        $template = $template instanceof SyllabusTemplate ? $template : null;

        return [
            'section_id' => [
                'required',
                'uuid',
                static function (string $attribute, mixed $value, \Closure $fail) use ($template): void {
                    if (! is_string($value) || $template?->section($value) === null) {
                        $fail('La sección seleccionada no pertenece a la plantilla.');
                    }
                },
            ],
            'block_ids' => ['required', 'array', 'min:1'],
            'block_ids.*' => [
                'required',
                'uuid',
                'distinct',
                static function (string $attribute, mixed $value, \Closure $fail) use ($template): void {
                    if (! is_string($value) || $template?->block($value) === null) {
                        $fail('El bloque seleccionado no pertenece a la plantilla.');
                    }
                },
            ],
        ];
    }
}
