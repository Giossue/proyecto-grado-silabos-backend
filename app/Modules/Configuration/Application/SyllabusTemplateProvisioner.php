<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SyllabusTemplateProvisioner
{
    public function createEmpty(): SyllabusTemplate
    {
        return DB::transaction(fn (): SyllabusTemplate => $this->newTemplate());
    }

    public function createWithOfficialFormat(): SyllabusTemplate
    {
        return DB::transaction(function (): SyllabusTemplate {
            $template = $this->newTemplate();
            $this->populateOfficialFormat($template);

            return $template;
        });
    }

    public function format(SyllabusTemplate $template): SyllabusTemplate
    {
        return DB::transaction(function () use ($template): SyllabusTemplate {
            $lockedTemplate = SyllabusTemplate::query()
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTemplate->hasStructure()) {
                throw ValidationException::withMessages([
                    'template' => 'Solo se puede aplicar el formato oficial a una plantilla vacía.',
                ]);
            }

            $this->populateOfficialFormat($lockedTemplate);

            return $lockedTemplate->fresh();
        });
    }

    private function newTemplate(): SyllabusTemplate
    {
        if (SyllabusTemplate::query()->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'template' => 'La plantilla institucional ya existe. Edítela en lugar de crear otra.',
            ]);
        }

        return SyllabusTemplate::query()->create([
            'documento' => [
                'version_esquema' => 1,
                'secciones' => [],
            ],
            'mapeo_documento' => [
                'appearance' => TemplateAppearance::defaults(),
                'title_block' => TemplateTitleBlock::defaults(),
            ],
        ]);
    }

    private function populateOfficialFormat(SyllabusTemplate $template): void
    {
        /** @var list<array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: string, 3: string|null}>}> $baseline */
        $baseline = config('syllabus_template.baseline', []);
        /** @var list<array{0: string, 1: string, 2: string, 3: list<string>|null, 4?: array{key: string, value: string}|null}> $identificationInputs */
        $identificationInputs = config('syllabus_template.identification_inputs', []);

        $sections = [];
        $fieldDefinitions = [];
        foreach ($baseline as $sectionPosition => [$sectionKey, $sectionTitle, $fields]) {
            $sectionId = (string) Str::uuid();
            $blocks = [];

            foreach ($fields as $blockPosition => [$fieldKey, $label, $contentType, $preset]) {
                $blockId = (string) Str::uuid();
                $configuration = ['content_type' => $contentType];
                if ($contentType === 'fixed') {
                    $configuration['document'] = match ($preset) {
                        'indicadores' => TemplateDocumentDefaults::evaluationIndicators(),
                        'escala' => TemplateDocumentDefaults::gradingScale(),
                        'firmas' => TemplateDocumentDefaults::signatures(),
                        default => TemplateDocumentDefaults::fixedPlaceholder(),
                    };
                }
                if ($contentType === 'free_text') {
                    $configuration = ['content_type' => 'text', 'document' => TemplateDocumentDefaults::freeTextSheet($fieldKey, $label)];
                }
                if ($contentType === 'institutional') {
                    $configuration['document'] = TemplateDocumentDefaults::identification();
                }

                $blocks[] = [
                    'id' => $blockId,
                    'clave' => $blockPosition === 0 ? "{$sectionKey}_principal" : "{$sectionKey}_{$fieldKey}",
                    'titulo' => $label,
                    'configuracion' => $configuration,
                    'posicion' => $blockPosition + 1,
                ];
                if ($contentType === 'fixed') {
                    continue;
                }
                $origin = $contentType === 'institutional' ? 'asignaturas' : null;

                $fieldDefinitions[] = [
                    'plantilla_id' => $template->id,
                    'campo_plantilla_id' => $blockId,
                    'clave' => $fieldKey,
                    'etiqueta' => $label,
                    'tipo' => $this->fieldType($contentType),
                    'heredado' => $origin !== null,
                    'origen_maestro' => $origin,
                    'editable_docente' => $origin === null,
                    'posicion' => 1,
                ];

                if ($contentType !== 'institutional') {
                    continue;
                }

                foreach ($identificationInputs as $inputPosition => $input) {
                    [$inputKey, $inputLabel, $inputType, $options] = $input;
                    $fieldDefinitions[] = [
                        'plantilla_id' => $template->id,
                        'campo_plantilla_id' => $blockId,
                        'clave' => $inputKey,
                        'etiqueta' => $inputLabel,
                        'tipo' => $inputType,
                        'heredado' => false,
                        'origen_maestro' => null,
                        'editable_docente' => true,
                        'opciones' => $options,
                        'mostrar_si' => $input[4] ?? null,
                        'posicion' => $inputPosition + 2,
                    ];
                }
            }

            $sections[] = [
                'id' => $sectionId,
                'clave' => $sectionKey,
                'titulo' => $sectionTitle,
                'descripcion' => null,
                'posicion' => $sectionPosition + 1,
                'bloques' => $blocks,
            ];
        }

        $template->update(['documento' => [
            'version_esquema' => 1,
            'secciones' => $sections,
        ]]);

        foreach ($fieldDefinitions as $fieldDefinition) {
            FieldDefinition::query()->create($fieldDefinition);
        }
    }

    private function fieldType(string $contentType): string
    {
        return match ($contentType) {
            'institutional' => 'referencia_maestra',
            default => 'texto_libre',
        };
    }
}
