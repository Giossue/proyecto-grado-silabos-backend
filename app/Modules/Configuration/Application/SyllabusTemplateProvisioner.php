<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Support\Facades\DB;
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
        $designs = app(SectionDesigns::class);

        $sections = [];
        $definitions = [];
        foreach ($designs->baseline() as $position => $key) {
            $built = $designs->instantiate($key, $template->id, $position + 1);
            $sections[] = $built['section'];
            array_push($definitions, ...$built['definitions']);
        }

        $template->update(['documento' => [
            'version_esquema' => 1,
            'secciones' => $sections,
        ]]);

        foreach ($definitions as $definition) {
            FieldDefinition::query()->create($definition);
        }
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
