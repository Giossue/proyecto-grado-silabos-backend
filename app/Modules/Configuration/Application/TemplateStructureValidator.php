<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Validation\ValidationException;

class TemplateStructureValidator
{
    /**
     * Same check as {@see assertUsable()}, without throwing: the reason why the template
     * cannot be used yet, or null when it can.
     */
    public function problem(?SyllabusTemplate $template): ?string
    {
        if ($template === null) {
            return 'La plantilla institucional no existe.';
        }

        try {
            $this->assertUsable($template);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            return is_string($message) ? $message : 'La plantilla no se puede usar.';
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function assertUsable(SyllabusTemplate $template, string $errorKey = 'template'): void
    {
        $missing = array_values(array_diff($template->definition()->requiredSections(), $template->sections->pluck('clave')->all()));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                $errorKey => 'La plantilla no tiene las áreas obligatorias: '.implode(', ', $missing).'.',
            ]);
        }

        foreach ($template->sections as $section) {
            if ($section->blocks->isEmpty()) {
                throw ValidationException::withMessages([$errorKey => "La sección «{$section->titulo}» de la plantilla está vacía."]);
            }

            foreach ($section->blocks as $block) {
                if ($block->fields->isEmpty()) {
                    throw ValidationException::withMessages([$errorKey => "La sección «{$block->titulo}» de la plantilla no tiene campos."]);
                }

                foreach ($block->fields as $field) {
                    FieldDocument::normalize($field->contenido, $errorKey);
                }
            }
        }
    }
}
