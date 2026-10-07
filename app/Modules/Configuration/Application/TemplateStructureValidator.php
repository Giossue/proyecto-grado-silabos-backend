<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use Illuminate\Validation\ValidationException;

class TemplateStructureValidator
{
    /** @var list<string> */
    public const REQUIRED_SECTIONS = [
        'identificacion',
        'descripcion',
        'objetivos',
        'resultados',
        'habilidades',
        'planificacion',
        'metodologia',
        'evaluacion',
        'perfil_egreso',
        'etica',
        'bibliografia',
        'revision',
    ];

    /** @throws ValidationException */
    public function assertUsable(SyllabusTemplate $template, string $errorKey = 'template'): void
    {
        $missing = array_values(array_diff(self::REQUIRED_SECTIONS, $template->sections->pluck('clave')->all()));
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
                if ($block->fields->isEmpty() && ! is_array($block->configuracion['document'] ?? null)) {
                    throw ValidationException::withMessages([$errorKey => "La sección «{$block->titulo}» de la plantilla no tiene campos."]);
                }

                foreach ($block->fields as $field) {
                    $this->assertField($field, $block, $errorKey);
                }
            }
        }
    }

    private function assertField(FieldDefinition $field, TemplateBlock $block, string $errorKey): void
    {
        $origin = $field->origen_maestro;
        $valid = match (true) {
            ! $field->heredado => $origin === null,
            $origin === 'asignaturas' => $field->clave === 'asignatura' && ! $field->editable_docente,
            $field->tipo === 'automatico' => $origin === ($field->typeConfig()['source'] ?? null) && ! $field->editable_docente,
            $origin === FieldDefinition::ORIGIN_PROFILE => in_array($field->tipo, ['texto_libre', 'ficha'], true),
            $origin === FieldDefinition::ORIGIN_TEACHER => $field->clave === 'formacion_experiencia' && ! $field->editable_docente,
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages([
                $errorKey => "El dato automático «{$field->etiqueta}» está mal configurado. Vuelva a cargar el formato oficial UEB.",
            ]);
        }

        if ($field->tipo === 'calculo') {
            throw ValidationException::withMessages([
                $errorKey => 'Los cálculos automáticos todavía no están disponibles.',
            ]);
        }
    }
}
