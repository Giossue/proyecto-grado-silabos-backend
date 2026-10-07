<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
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
        /** @var list<array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}> $baseline */
        $baseline = config('syllabus_template.baseline', []);
        $types = app(FieldTypeRegistry::class);

        $sections = [];
        $fieldDefinitions = [];
        foreach ($baseline as $sectionPosition => [$sectionKey, $sectionTitle, $fields]) {
            $blocks = [];
            foreach ($fields as $blockPosition => [$fieldKey, $label, $typeKey, $rawConfig]) {
                $type = $types->get($typeKey);
                $config = $type->normalizeConfig($this->expandConfig($rawConfig));
                $blockId = (string) Str::uuid();

                $blocks[] = [
                    'id' => $blockId,
                    'clave' => $fieldKey,
                    'titulo' => $label,
                    'configuracion' => ['content_type' => 'text', 'document' => $typeKey === 'texto' || $typeKey === 'ficha'
                        ? TemplateDocumentDefaults::freeTextSheet($fieldKey, $label)
                        : TemplateDocumentDefaults::blankSheet()],
                    'posicion' => $blockPosition + 1,
                ];
                $fieldDefinitions[] = [
                    'plantilla_id' => $template->id,
                    'campo_plantilla_id' => $blockId,
                    'clave' => $fieldKey,
                    'etiqueta' => $label,
                    'tipo' => $typeKey,
                    'posicion' => 1,
                    'opciones' => $config === [] ? null : $config,
                    'mostrar_si' => null,
                    ...$this->inheritance($typeKey, $config),
                ];
            }

            $sections[] = [
                'id' => (string) Str::uuid(),
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

    /**
     * Convierte la forma corta de la configuración (`clave => etiqueta`, `weeks`) en la del registro.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function expandConfig(array $config): array
    {
        $axis = fn (array $items): array => array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            array_keys($items),
            array_values($items),
        );
        if (isset($config['content']) && is_string($config['content'])) {
            $config['content'] = FixedContent::official($config['content']);
        }
        if (isset($config['weeks'])) {
            $config['rows'] = [];
            for ($week = 1; $week <= (int) $config['weeks']; $week++) {
                $config['rows']['semana_'.$week] = (string) $week;
            }
            unset($config['weeks']);
        }
        foreach (['columns', 'rows'] as $name) {
            if (isset($config[$name])) {
                $config[$name] = $axis($config[$name]);
            }
        }

        return $config;
    }

    /**
     * Columnas de la definición que dependen del tipo (igual que SaveTemplateStructure).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function inheritance(string $typeKey, array $config): array
    {
        return match ($typeKey) {
            'ficha' => [
                'heredado' => true,
                'origen_maestro' => FieldDefinition::ORIGIN_PROFILE,
                'editable_docente' => (bool) ($config['adjustable'] ?? false),
            ],
            'automatico' => ['heredado' => true, 'origen_maestro' => (string) $config['source'], 'editable_docente' => false],
            'fijo' => ['heredado' => false, 'origen_maestro' => null, 'editable_docente' => false],
            default => ['heredado' => false, 'origen_maestro' => null, 'editable_docente' => true],
        };
    }

    /**
     * Campos que llena quien arma el sílabo dentro de la tabla de identificación (discapacidad, formación…).
     *
     * @return list<array<string, mixed>>
     */
    public function identificationInputs(string $templateId, string $blockId): array
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: list<string>|null, 4?: array{key: string, value: string}|null}> $inputs */
        $inputs = config('syllabus_template.identification_inputs', []);
        $definitions = [];
        foreach ($inputs as $position => [$key, $label, $type, $options]) {
            $teacher = $key === 'formacion_experiencia';
            $definitions[] = [
                'plantilla_id' => $templateId,
                'campo_plantilla_id' => $blockId,
                'clave' => $key,
                'etiqueta' => $label,
                'tipo' => $type,
                'heredado' => $teacher,
                'origen_maestro' => $teacher ? FieldDefinition::ORIGIN_TEACHER : null,
                'editable_docente' => ! $teacher,
                'opciones' => $options,
                'mostrar_si' => $inputs[$position][4] ?? null,
                'posicion' => $position + 2,
            ];
        }

        return $definitions;
    }
}
