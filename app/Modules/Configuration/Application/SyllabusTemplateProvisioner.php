<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FieldDocument;
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

            $lockedTemplate = $this->retireStructure($lockedTemplate);

            $this->populateOfficialFormat($lockedTemplate);

            return $lockedTemplate->fresh();
        });
    }

    public function clear(SyllabusTemplate $template): SyllabusTemplate
    {
        return DB::transaction(function () use ($template): SyllabusTemplate {
            $lockedTemplate = SyllabusTemplate::query()
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $this->retireStructure($lockedTemplate)->fresh();
        });
    }

    /**
     * @param  list<array{key: string, title: string, orientation?: string, fields?: list<array{key: string, label?: string|null, help?: string|null, quien_llena: string, content: array<string, mixed>}>}>  $structure
     */
    public function replaceStructure(SyllabusTemplate $template, array $structure): SyllabusTemplate
    {
        return DB::transaction(function () use ($template, $structure): SyllabusTemplate {
            $locked = SyllabusTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            $locked = $this->retireStructure($locked);

            $sections = [];
            $definitions = [];
            foreach (array_values($structure) as $sectionIndex => $section) {
                $blocks = [];
                foreach (array_values($section['fields'] ?? []) as $fieldIndex => $field) {
                    $blockId = (string) Str::uuid();
                    $blocks[] = [
                        'id' => $blockId,
                        'clave' => $field['key'],
                        'titulo' => (string) ($field['label'] ?? ''),
                        'configuracion' => [],
                        'posicion' => $fieldIndex + 1,
                    ];
                    $definitions[] = [
                        'plantilla_id' => $locked->id,
                        'campo_plantilla_id' => $blockId,
                        'clave' => $field['key'],
                        'etiqueta' => (string) ($field['label'] ?? ''),
                        'ayuda' => $field['help'] ?? null,
                        'quien_llena' => $field['quien_llena'],
                        'contenido' => FieldDocument::normalize($field['content']),
                        'posicion' => 1,
                    ];
                }
                $sections[] = [
                    'id' => (string) Str::uuid(),
                    'clave' => $section['key'],
                    'titulo' => $section['title'],
                    'descripcion' => null,
                    'posicion' => $sectionIndex + 1,
                    'bloques' => $blocks,
                    'orientacion' => $section['orientation'] ?? 'portrait',
                ];
            }

            $locked->update(['documento' => ['version_esquema' => 1, 'secciones' => $sections]]);
            foreach ($definitions as $definition) {
                FieldDefinition::query()->create($definition);
            }

            return $locked->fresh();
        });
    }

    private function retireStructure(SyllabusTemplate $template): SyllabusTemplate
    {
        if (! $template->hasStructure()) {
            return $template;
        }

        $template->update(['documento' => ['version_esquema' => 1, 'secciones' => []]]);
        DB::table('definiciones_campo')
            ->where('plantilla_id', $template->id)
            ->where('vigente_en_plantilla', false)
            ->update(['clave_definicion_campo' => DB::raw("clave_definicion_campo || '-' || substr(definicion_campo_id::text, 1, 8)")]);
        DB::table('secciones_plantilla')
            ->where('plantilla_id', $template->id)
            ->where('vigente_en_plantilla', false)
            ->update(['clave_seccion_plantilla' => DB::raw("clave_seccion_plantilla || '-' || substr(seccion_plantilla_id::text, 1, 8)")]);

        return $template->fresh();
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
}
