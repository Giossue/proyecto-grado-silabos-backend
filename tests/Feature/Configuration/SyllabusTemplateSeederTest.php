<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyllabusTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_installs_the_initial_template_once_with_structured_text_fields(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->seed(SyllabusTemplateSeeder::class);
        $template = SyllabusTemplate::query()->firstOrFail();
        $sectionCount = $template->sections->count();
        $blockCount = $template->blocks()->count();
        $fieldCount = $template->fields()->count();

        $this->assertSame(12, $sectionCount);
        $this->assertGreaterThan(0, $blockCount);
        $this->assertGreaterThan(0, $fieldCount);
        $this->assertDatabaseMissing('definiciones_campo', ['tipo_definicion_campo' => 'markdown']);
        $this->assertNotNull($template->sections->firstWhere('clave', 'identificacion')?->blocks->first()?->configuracion['document'] ?? null);

        $this->seed(SyllabusTemplateSeeder::class);

        $this->assertSame(1, SyllabusTemplate::query()->count());
        $this->assertSame($sectionCount, SyllabusTemplate::query()->firstOrFail()->sections()->count());
        $this->assertSame($blockCount, SyllabusTemplate::query()->firstOrFail()->blocks()->count());
        $this->assertSame($fieldCount, SyllabusTemplate::query()->firstOrFail()->fields()->count());
    }

    public function test_it_formats_an_existing_empty_container_without_creating_another_template(): void
    {
        $this->seed(DatabaseSeeder::class);
        $template = app(SyllabusTemplateProvisioner::class)->createEmpty();

        $this->seed(SyllabusTemplateSeeder::class);

        $this->assertSame(1, SyllabusTemplate::query()->count());
        $this->assertCount(12, $template->fresh()->sections());
        $this->assertGreaterThan(0, $template->fresh()->fields()->count());
    }

    public function test_the_official_template_is_built_only_with_the_four_field_types_and_is_usable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SyllabusTemplateSeeder::class);
        $template = SyllabusTemplate::query()->firstOrFail();

        app(TemplateStructureValidator::class)->assertUsable($template);

        $fields = $template->fields()->get()->keyBy('clave');
        $types = $fields->map(fn ($field) => $field->fieldType()->key());
        $this->assertSame([], $fields->filter(fn ($field) => $field->hasLegacyClass())->keys()->all());
        $this->assertSame('automatico', $types['asignatura']);
        $this->assertSame(['source' => 'identification_card'], $fields['asignatura']->typeConfig());
        $this->assertNull($fields['asignatura']->mostrar_si);
        foreach (['discapacidad_tipo', 'discapacidad_adaptacion', 'formacion_experiencia', 'metodologia'] as $key) {
            $this->assertSame('texto', $types[$key], $key);
            $this->assertNull($fields[$key]->mostrar_si, $key);
        }
        foreach (['descripcion', 'objetivo_general', 'objetivos_especificos', 'resultados_aprendizaje', 'habilidades_blandas', 'herramientas_tic', 'contribucion_perfil', 'bibliografia', 'bibliografia_complementaria'] as $key) {
            $this->assertSame('ficha', $types[$key], $key);
            $this->assertTrue($fields[$key]->heredado, $key);
        }
        $this->assertTrue($fields['bibliografia']->typeConfig()['adjustable']);
        $this->assertTrue($fields['bibliografia_complementaria']->typeConfig()['adjustable']);
        $this->assertFalse($fields['descripcion']->typeConfig()['adjustable']);

        $planning = $fields['unidades'];
        $this->assertSame('tabla', $types['unidades']);
        $this->assertCount(16, $planning->typeConfig()['rows']);
        $this->assertSame('Contenidos temáticos', $planning->typeConfig()['columns'][0]['label']);
        $this->assertCount(8, $planning->typeConfig()['columns']);
        $this->assertSame('tabla', $types['firmas']);

        foreach ($template->blocks() as $block) {
            $this->assertNotContains($block->configuredContentType(), ['fixed', 'institutional', 'flow'], $block->titulo);
        }
    }
}
