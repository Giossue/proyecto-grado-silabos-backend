<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Application\SectionDesigns;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class SectionDesignsTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase;

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla MVP'])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
    }

    private function actingAsRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $assignment = $user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);
    }

    private function addDesign(string $design, array $extra = []): TestResponse
    {
        return $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['design' => $design, ...$extra]);
    }

    public function test_the_catalog_lists_the_twelve_official_sections_with_their_fields(): void
    {
        $designs = $this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk()->json('sectionDesigns');

        $this->assertSame(
            ['identificacion', 'descripcion', 'objetivos', 'resultados', 'habilidades', 'planificacion', 'metodologia', 'evaluacion', 'perfil_egreso', 'etica', 'bibliografia', 'revision'],
            array_column($designs, 'key'),
        );
        $this->assertSame(array_column($designs, 'key'), config('syllabus_template.baseline'));
        foreach ($designs as $design) {
            $this->assertNotSame('', $design['title']);
            $this->assertNotSame('', $design['summary']);
            $this->assertNotEmpty($design['fields']);
            $this->assertFalse($design['in_use']);
        }
        $identification = collect($designs)->firstWhere('key', 'identificacion');
        $this->assertSame(['automatico', 'texto', 'texto', 'texto'], array_column($identification['fields'], 'type'));
        $this->assertSame('Identificación Institucional', $identification['title']);
    }

    public function test_adding_a_design_creates_its_section_with_every_field_defined(): void
    {
        $this->addDesign('identificacion')->assertCreated();

        $template = $this->template->fresh();
        $section = $template->sections()->first();
        $this->assertSame('Identificación Institucional', $section->titulo);
        $this->assertSame('identificacion', $section->clave);
        $fields = FieldDefinition::query()->where('plantilla_id', $template->id)->get()->keyBy('clave');
        $this->assertEqualsCanonicalizing(['asignatura', 'discapacidad_tipo', 'discapacidad_adaptacion', 'formacion_experiencia'], $fields->keys()->all());
        $this->assertSame('automatico', $fields['asignatura']->fieldType()->key());
        $this->assertSame(['source' => 'identification_card'], $fields['asignatura']->typeConfig());
        $this->assertTrue($fields['asignatura']->heredado);
        foreach (['discapacidad_tipo', 'discapacidad_adaptacion', 'formacion_experiencia'] as $key) {
            $this->assertSame('texto', $fields[$key]->fieldType()->key());
            $this->assertSame($key === 'formacion_experiencia' ? ['inside' => 'identification_card'] : ['inside' => 'identification_card', 'optional' => true], $fields[$key]->typeConfig());
            $this->assertSame($key !== 'formacion_experiencia', $fields[$key]->editable_docente);
        }
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.seccion_creada']);

        $payload = $this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk();
        $this->assertTrue(collect($payload->json('sectionDesigns'))->firstWhere('key', 'identificacion')['in_use']);
        $this->assertCount(4, $payload->json('template.sections.0.blocks'));
    }

    public function test_each_design_is_added_once_and_unknown_designs_are_rejected(): void
    {
        $this->addDesign('etica')->assertCreated();
        $this->addDesign('etica')->assertJsonValidationErrors('design');
        $this->addDesign('inventada')->assertJsonValidationErrors('design');
        $this->assertCount(1, $this->template->fresh()->sections());
        $this->assertSame(1, FieldDefinition::query()->where('plantilla_id', $this->template->id)->count());
    }

    public function test_a_blank_section_still_needs_a_key_and_a_title(): void
    {
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), [])->assertJsonValidationErrors(['title', 'key']);
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'libre', 'title' => 'Libre'])->assertCreated();
        $this->assertSame('Libre', $this->template->fresh()->sections()->first()->titulo);
    }

    public function test_a_design_accepts_its_own_title_and_position(): void
    {
        $this->addDesign('etica')->assertCreated();
        $this->addDesign('revision', ['title' => 'Firmas del sílabo', 'position' => 1])->assertCreated();

        $titles = $this->template->fresh()->sections()->sortBy('posicion')->pluck('titulo')->all();
        $this->assertSame(['Firmas del sílabo', 'Conducta y comportamiento ético'], $titles);
    }

    public function test_adding_every_design_builds_the_usable_official_template(): void
    {
        foreach (app(SectionDesigns::class)->baseline() as $key) {
            $this->addDesign($key)->assertCreated();
        }

        app(TemplateStructureValidator::class)->assertUsable($this->template->fresh());
        $sections = $this->template->fresh()->sections();
        $this->assertCount(12, $sections);
        $fields = FieldDefinition::query()->where('plantilla_id', $this->template->id)->get();
        $this->assertSame([], $fields->filter(fn ($field) => $field->hasLegacyClass())->all());
        $this->assertSame(['automatico' => 1, 'ficha' => 13, 'fijo' => 4, 'texto' => 3, 'unidades' => 1], $fields->countBy(fn ($field) => $field->fieldType()->key())->sortKeys()->all());
        $this->assertSame(
            collect(config('syllabus_template.designs'))->pluck('title')->values()->all(),
            $sections->sortBy('posicion')->pluck('titulo')->values()->all(),
        );
    }

    public function test_official_fixed_content_follows_the_reference_pdf(): void
    {
        foreach (['evaluacion', 'etica', 'revision'] as $key) {
            $this->addDesign($key)->assertCreated();
        }
        $fields = FieldDefinition::query()->where('plantilla_id', $this->template->id)->get()->keyBy('clave');
        $text = fn (string $key): string => FreeText::plainText($fields[$key]->typeConfig()['content']);

        foreach (['INDICADORES', 'PRIMER PARCIAL', 'SEGUNDO PARCIAL', 'PONDERACIÓN', '2.5 PUNTOS', '1.25', '3.5 PUNTOS', '1.75', '10 PUNTOS', '5 PUNTOS'] as $expected) {
            $this->assertStringContainsString($expected, $text('indicadores_evaluacion'));
        }
        foreach (['ESCALA CUALITATIVA', 'Igual a 10 o mayor o igual que 9', 'Menos de 6', 'Recuperación', 'No aprueba'] as $expected) {
            $this->assertStringContainsString($expected, $text('componentes_evaluacion'));
        }
        foreach (['Puntualidad:', 'Acceso a la Video Conferencia:', 'Registro de Asistencia:', 'Trabajo en Equipo:', 'Dentro de los estándares de comportamiento', 'Se considera inadmisible lo siguiente:', 'Las violaciones serán analizadas'] as $expected) {
            $this->assertStringContainsString($expected, $text('conducta_etica'));
        }
        foreach (['Firma:', 'Nombre:', 'Bibliotecario', 'Fecha de entrega:', 'Fecha de revisión:', 'Fecha de verificación:'] as $expected) {
            $this->assertStringContainsString($expected, $text('firmas'));
        }
    }

    public function test_only_administration_adds_designs_and_an_open_process_blocks_it(): void
    {
        foreach (['coordinador@silabos.test', 'docente@silabos.test'] as $email) {
            $this->actingAsRole($email);
            $this->addDesign('etica')->assertForbidden();
        }
        $this->assertCount(0, $this->template->fresh()->sections());

        $this->actingAsRole('admin@silabos.test');
        $this->openSyllabusProcess($this->template->id);
        $this->addDesign('etica')->assertJsonValidationErrors('process');
    }
}
