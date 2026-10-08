<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Domain\FreeText;
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

    public function test_the_official_template_is_built_only_with_the_five_field_types_and_is_usable(): void
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
        foreach (['discapacidad_tipo', 'discapacidad_adaptacion', 'formacion_experiencia'] as $key) {
            $this->assertSame('texto', $types[$key], $key);
            $this->assertNull($fields[$key]->mostrar_si, $key);
        }
        foreach (['descripcion', 'objetivo_general', 'objetivos_especificos', 'resultados_aprendizaje', 'habilidades_blandas', 'contribucion_perfil', 'metodologia', 'tecnicas_ensenanza', 'recursos_medios', 'herramientas_tic', 'ambientes_aprendizaje', 'bibliografia', 'bibliografia_complementaria'] as $key) {
            $this->assertSame('ficha', $types[$key], $key);
            $this->assertTrue($fields[$key]->heredado, $key);
        }
        $this->assertTrue($fields['bibliografia']->typeConfig()['adjustable']);
        $this->assertTrue($fields['bibliografia']->typeConfig()['optional']);
        $this->assertTrue($fields['bibliografia_complementaria']->typeConfig()['optional']);
        $this->assertFalse($fields['descripcion']->typeConfig()['adjustable']);

        $planning = $fields['unidades'];
        $this->assertSame('unidades', $types['unidades']);
        $this->assertSame([], $planning->typeConfig());
        foreach (['indicadores_evaluacion', 'componentes_evaluacion', 'conducta_etica', 'firmas'] as $key) {
            $this->assertSame('fijo', $types[$key], $key);
            $this->assertFalse($fields[$key]->editable_docente, $key);
            $this->assertFalse(FreeText::isEmpty($fields[$key]->typeConfig()['content']), $key);
        }
        $this->assertStringContainsString('PRIMER PARCIAL', FreeText::plainText($fields['indicadores_evaluacion']->typeConfig()['content']));
        $this->assertStringContainsString('Excelente', FreeText::plainText($fields['componentes_evaluacion']->typeConfig()['content']));
        $this->assertStringContainsString('Fecha de entrega:', FreeText::plainText($fields['firmas']->typeConfig()['content']));
        $ethics = FreeText::plainText($fields['conducta_etica']->typeConfig()['content']);
        foreach (['código de ética y políticas de acción afirmativa', 'Puntualidad:', 'ZOOM', 'Registro de Asistencia:', 'UNO sobre diez', 'falsificación de datos'] as $text) {
            $this->assertStringContainsString($text, $ethics);
        }
        $this->assertStringNotContainsString('pendiente', $ethics);
        foreach (['discapacidad_tipo', 'discapacidad_adaptacion'] as $key) {
            $this->assertSame(['inside' => 'identification_card', 'optional' => true], $fields[$key]->typeConfig(), $key);
        }
        $this->assertSame(['inside' => 'identification_card'], $fields['formacion_experiencia']->typeConfig());
        $this->assertTrue($fields['formacion_experiencia']->heredado);
        $this->assertFalse($fields['formacion_experiencia']->editable_docente);

        foreach ($template->blocks() as $block) {
            $this->assertNotContains($block->configuredContentType(), ['fixed', 'institutional', 'flow'], $block->titulo);
        }
    }

    public function test_the_official_template_reproduces_the_sections_and_fields_of_the_reference_pdf(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SyllabusTemplateSeeder::class);
        $template = SyllabusTemplate::query()->firstOrFail();

        $this->assertSame('1. Identificación Institucional', $template->sections->first()->titulo);
        $structure = $template->sections->map(fn ($section) => [
            preg_replace('/^\d+\.\s*/', '', $section->titulo),
            $section->blocks->flatMap(fn ($block) => $block->fields)->map(fn ($field) => $field->etiqueta.'|'.$field->fieldType()->key())->all(),
        ])->all();

        $this->assertSame([
            ['Identificación Institucional', ['|automatico', 'Estudiantes con discapacidad: Tipo de discapacidad|texto', 'Estudiantes con discapacidad: Breve descripción|texto', 'Formación y experiencia académica – investigativa|texto']],
            ['Descripción de la asignatura', ['|ficha']],
            ['Objetivo(s) de la asignatura', ['Objetivo General|ficha', 'Objetivos específicos|ficha']],
            ['Resultados de aprendizaje de la asignatura', ['|ficha']],
            ['Habilidades Blandas de la asignatura', ['|ficha']],
            ['Distribución y planificación de las unidades curriculares', ['|unidades']],
            ['Metodología y Ambientes de Aprendizaje', [
                '7.1 Métodos de Enseñanza – Aprendizaje|ficha', '7.2 Técnicas de enseñanza|ficha', '7.3 Recursos y medios didácticos|ficha',
                '7.4 Herramientas pedagógicas, Tecnologías de aprendizaje y conocimiento e Inteligencia Artificial en los procesos enseñanza-aprendizaje|ficha',
                '7.5 Ambientes o escenarios de aprendizaje|ficha',
            ]],
            ['EVALUACIÓN DE LOS APRENDIZAJES', ['|fijo', 'ESCALA DE VALORACIÓN|fijo']],
            ['Relación de la asignatura con los resultados de aprendizaje del Perfil de egreso de la carrera', ['|ficha']],
            ['Conducta y comportamiento ético', ['|fijo']],
            ['Bibliografía Básica', ['|ficha', 'Complementaria|ficha']],
            ['Revisión y aprobación', ['|fijo']],
        ], $structure);

        $planning = $template->sections->firstWhere('clave', 'planificacion');
        $this->assertSame('landscape', $planning->blocks->first()->pageOrientation());
        $this->assertNull($template->sections->firstWhere('clave', 'descripcion')->blocks->first()->pageOrientation());
    }
}
