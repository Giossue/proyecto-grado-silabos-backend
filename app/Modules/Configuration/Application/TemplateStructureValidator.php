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
        $bibliography = in_array($field->origen_maestro, ['fichas_asignatura.bibliografia_basica', 'fichas_asignatura.bibliografia_complementaria'], true);
        $profileSources = [
            'descripcion' => 'descripcion', 'objetivo_general' => 'objetivo_general',
            'objetivos_especificos' => 'objetivos_especificos',
            'resultados_aprendizaje' => 'resultados_aprendizaje',
            'habilidades_blandas' => 'habilidades_blandas',
            'herramientas_tic' => 'herramientas_tic',
            'contribucion_perfil' => 'relacion_perfil_egreso',
            'bibliografia' => 'bibliografia_basica',
            'bibliografia_complementaria' => 'bibliografia_complementaria',
        ];
        $expected = $field->clave === 'asignatura' ? 'asignaturas'
            : (isset($profileSources[$field->clave]) ? 'fichas_asignatura.'.$profileSources[$field->clave] : null);
        if (($field->heredado && ($expected === null || $field->origen_maestro !== $expected || ($field->editable_docente && ! $bibliography)))
            || (! $field->heredado && $field->origen_maestro !== null)) {
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
