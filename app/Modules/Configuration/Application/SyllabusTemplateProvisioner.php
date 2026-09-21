<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\TablePresets;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Instala el formato inicial sin mezclar datos de seed con autorización HTTP. */
final class SyllabusTemplateProvisioner
{
    public function create(): SyllabusTemplate
    {
        return DB::transaction(function (): SyllabusTemplate {
            if (SyllabusTemplate::query()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'template' => 'La plantilla institucional ya existe. Edítela en lugar de crear otra.',
                ]);
            }

            $template = SyllabusTemplate::query()->create([
                'mapeo_documento' => [
                    'appearance' => TemplateAppearance::defaults(),
                    'title_block' => TemplateTitleBlock::defaults(),
                ],
            ]);

            /** @var list<array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: string, 3: string|null}>}> $baseline */
            $baseline = config('syllabus_template.baseline', []);
            /** @var list<array{0: string, 1: string, 2: string, 3: list<string>|null}> $identificationInputs */
            $identificationInputs = config('syllabus_template.identification_inputs', []);

            foreach ($baseline as $sectionPosition => [$sectionKey, $sectionTitle, $fields]) {
                $section = TemplateSection::query()->create([
                    'plantilla_id' => $template->id,
                    'clave' => $sectionKey,
                    'titulo' => $sectionTitle,
                    'posicion' => $sectionPosition + 1,
                ]);

                foreach ($fields as $blockPosition => [$fieldKey, $label, $contentType, $preset]) {
                    $configuration = ['content_type' => $contentType];
                    if ($preset !== null) {
                        $configuration['table'] = TablePresets::layout($preset);
                    }
                    if ($preset === 'planificacion') {
                        $configuration['page_orientation'] = 'landscape';
                    }
                    if ($contentType === 'institutional') {
                        $configuration['document'] = TemplateDocumentDefaults::identification();
                    }

                    $block = TemplateBlock::query()->create([
                        'plantilla_id' => $template->id,
                        'seccion_plantilla_id' => $section->id,
                        'clave' => $blockPosition === 0 ? "{$sectionKey}_principal" : "{$sectionKey}_{$fieldKey}",
                        'tipo' => $this->blockType($contentType),
                        'titulo' => $label,
                        'configuracion' => $configuration,
                        'posicion' => $blockPosition + 1,
                    ]);
                    $origin = match ($contentType) {
                        'institutional' => 'asignaturas',
                        'flow' => 'flujo',
                        default => null,
                    };

                    FieldDefinition::query()->create([
                        'plantilla_id' => $template->id,
                        'bloque_plantilla_id' => $block->id,
                        'clave' => $fieldKey,
                        'etiqueta' => $label,
                        'tipo' => $this->fieldType($contentType),
                        'heredado' => $origin !== null,
                        'origen_maestro' => $origin,
                        'editable_docente' => $origin === null,
                        'ia_habilitada' => $contentType === 'text',
                        'ia_coordinacion_configurable' => $origin === null,
                        'posicion' => 1,
                    ]);

                    if ($contentType !== 'institutional') {
                        continue;
                    }

                    foreach ($identificationInputs as $inputPosition => [$inputKey, $inputLabel, $inputType, $options]) {
                        FieldDefinition::query()->create([
                            'plantilla_id' => $template->id,
                            'bloque_plantilla_id' => $block->id,
                            'clave' => $inputKey,
                            'etiqueta' => $inputLabel,
                            'tipo' => $inputType,
                            'heredado' => false,
                            'origen_maestro' => null,
                            'editable_docente' => true,
                            'ia_habilitada' => $inputType === 'texto_largo',
                            'ia_coordinacion_configurable' => true,
                            'opciones' => $options,
                            'posicion' => $inputPosition + 2,
                        ]);
                    }
                }
            }

            return $template;
        });
    }

    private function blockType(string $contentType): string
    {
        return match ($contentType) {
            'flow' => 'flujo',
            'institutional', 'text' => 'narrativa',
            default => 'repetible',
        };
    }

    private function fieldType(string $contentType): string
    {
        return match ($contentType) {
            'institutional', 'flow' => 'referencia_maestra',
            'text' => 'texto_largo',
            default => 'repetible',
        };
    }
}
