<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'tipo_definicion_campo'));
        $this->assertSame('table', $template->fields()->where('clave', 'asignatura')->firstOrFail()->contenido['content'][0]['type']);

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

    public function test_the_official_template_uses_documents_and_the_confirmed_responsibles(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SyllabusTemplateSeeder::class);
        $template = SyllabusTemplate::query()->firstOrFail();
        app(TemplateStructureValidator::class)->assertUsable($template);
        $fields = $template->fields()->get()->keyBy('clave');
        $this->assertSame(['coordinacion' => 6, 'docente' => 10, 'institucional' => 5], $fields->countBy('quien_llena')->sortKeys()->all());
        foreach ($fields as $field) {
            $this->assertEquals($field->contenido, FieldDocument::normalize($field->contenido));
        }
        foreach (['descripcion', 'objetivo_general', 'objetivos_especificos', 'resultados_aprendizaje', 'habilidades_blandas', 'contribucion_perfil'] as $key) {
            $this->assertSame('coordinacion', $fields[$key]->quien_llena);
        }
        foreach (['bibliografia', 'bibliografia_complementaria', 'formacion_experiencia', 'metodologia', 'unidades'] as $key) {
            $this->assertSame('docente', $fields[$key]->quien_llena);
        }
        $this->assertSame('landscape', $template->sections->firstWhere('clave', 'planificacion')->orientacion);
        $this->assertSame('portrait', $template->sections->firstWhere('clave', 'descripcion')->orientacion);
        $this->assertSame(config('syllabus_template.baseline'), $template->sections->pluck('clave')->all());
        $planning = TemplateDocument::nodes($fields['unidades']->contenido, 'table')[1];
        $this->assertSame('table', $planning['type']);
        $this->assertCount(20, $planning['content']);
        $this->assertSame('TOTAL', FreeText::plainText($planning['content'][19]['content'][0]));
        $this->assertSame('', FreeText::plainText($planning['content'][19]['content'][2]));
    }

    public function test_the_official_document_tables_use_the_pdf_layout_and_catalog_variables(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SyllabusTemplateSeeder::class);
        $template = SyllabusTemplate::query()->firstOrFail();
        $fields = $template->fields()->get()->keyBy('clave');
        $identification = $fields['asignatura']->contenido;
        $variables = array_column(array_column(TemplateDocument::nodes($identification, 'variable'), 'attrs'), 'id');
        $this->assertCount(22, $variables);
        foreach (['nombre_facultad', 'codigo_paralelo', 'horas_docencia', 'horas_practicas', 'horas_autonomas', 'marca_unidad_basica', 'marca_unidad_profesional', 'marca_unidad_titulacion', 'nombre_docente', 'correo_docente'] as $key) {
            $this->assertContains($key, $variables);
        }
        $this->assertSame(9, $identification['content'][0]['content'][0]['content'][0]['attrs']['colspan']);
        $this->assertSame('#4F81BD', $identification['content'][0]['content'][0]['content'][0]['attrs']['backgroundColor']);
        $planning = TemplateDocument::nodes($fields['unidades']->contenido, 'table')[1];
        $weekRows = array_slice($planning['content'], 3, 16);
        $this->assertSame(array_map(strval(...), range(1, 16)), array_map(fn (array $row): string => FreeText::plainText($row['content'][1]), $weekRows));
        foreach ($weekRows as $row) {
            foreach ([2, 3, 4] as $column) {
                $this->assertSame('', FreeText::plainText($row['content'][$column]));
            }
        }
        foreach (['bibliografia', 'bibliografia_complementaria'] as $key) {
            $headers = $fields[$key]->contenido['content'][0]['content'][0]['content'];
            $this->assertSame(['Autor', 'Título', 'Año', 'Ciudad', 'Editorial', 'ISBN', 'Código'], array_map(FreeText::plainText(...), $headers));
        }
        $this->assertStringContainsString('Nivel de contribución', FreeText::plainText($fields['contribucion_perfil']->contenido));
        $this->assertSame('Revisión y aprobación', $template->sections->firstWhere('clave', 'revision')->titulo);
        $signatureKeys = array_column(array_column(TemplateDocument::nodes($fields['firmas']->contenido, 'variable'), 'attrs'), 'id');
        $this->assertEqualsCanonicalizing(['firma_docente', 'firma_coordinador', 'firma_bibliotecario', 'nombre_docente', 'nombre_coordinador', 'fecha_entrega', 'fecha_revision'], $signatureKeys);
    }
}
