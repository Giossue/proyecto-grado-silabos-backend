<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class OfficialTemplateTest extends TestCase
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

    private function format(): TestResponse
    {
        return $this->postJson(route('api.v1.admin.template.format', $this->template));
    }

    public function test_the_official_format_builds_the_usable_template_of_twelve_sections(): void
    {
        $this->format()->assertOk()->assertJsonPath('message', 'Plantilla restablecida al formato oficial.');

        app(TemplateStructureValidator::class)->assertUsable($this->template->fresh());
        $sections = $this->template->fresh()->sections();
        $this->assertCount(12, $sections);
        $fields = FieldDefinition::query()->where('plantilla_id', $this->template->id)->get();
        $this->assertSame(['coordinacion' => 6, 'docente' => 10, 'institucional' => 5], $fields->countBy('quien_llena')->sortKeys()->all());
        $this->assertSame(config('syllabus_template.baseline'), array_keys(config('syllabus_template.designs')));
        $this->assertSame(
            collect(config('syllabus_template.baseline'))->values()->map(fn ($key, $index) => ((config("syllabus_template.designs.{$key}.numbered") ?? true) ? ($index + 1).'. ' : '').config("syllabus_template.designs.{$key}.title"))->all(),
            $sections->sortBy('posicion')->pluck('titulo')->values()->all(),
        );
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.formateada']);
    }

    public function test_the_template_can_be_reset_to_the_official_format_after_editing_it(): void
    {
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'libre', 'title' => 'Mi sección'])->assertCreated();
        $this->format()->assertOk();
        $this->format()->assertOk();

        $template = $this->template->fresh();
        $this->assertCount(12, $template->sections());
        $this->assertNull($template->sections()->firstWhere('clave', 'libre'));
        $this->assertSame(21, FieldDefinition::query()->where('plantilla_id', $template->id)->count());
    }

    public function test_the_template_can_be_cleared_and_loaded_again(): void
    {
        $this->format()->assertOk();
        $this->deleteJson(route('api.v1.admin.template.clear', $this->template))->assertOk()->assertJsonPath('message', 'Plantilla vaciada.');

        $this->assertCount(0, $this->template->fresh()->sections());
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.vaciada']);
        $this->deleteJson(route('api.v1.admin.template.clear', $this->template))->assertOk();
        $this->format()->assertOk();
        $this->assertCount(12, $this->template->fresh()->sections());
    }

    public function test_only_administration_clears_the_template(): void
    {
        $this->format()->assertOk();
        $this->actingAsRole('coordinador@silabos.test');
        $this->deleteJson(route('api.v1.admin.template.clear', $this->template))->assertForbidden();
        $this->assertCount(12, $this->template->fresh()->sections());
    }

    public function test_a_blank_section_needs_a_key_and_a_title_and_ignores_designs(): void
    {
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['design' => 'etica'])->assertJsonValidationErrors(['title', 'key']);
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'libre', 'title' => 'Libre'])->assertCreated();
        $this->assertSame('Libre', $this->template->fresh()->sections()->first()->titulo);
        $this->assertSame(0, FieldDefinition::query()->where('plantilla_id', $this->template->id)->count());
    }

    public function test_the_payload_offers_the_three_ready_made_templates(): void
    {
        $presets = collect($this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk()->json('presets'));

        $this->assertSame(['identificacion', 'planificacion', 'firmas'], $presets->pluck('key')->all());
        $this->assertSame(['institucional', 'docente', 'institucional'], $presets->pluck('quien_llena')->all());
        $this->assertSame('table', $presets->firstWhere('key', 'identificacion')['content']['content'][0]['type']);
        $this->assertNotSame('', $presets->firstWhere('key', 'firmas')['label']);
    }

    public function test_the_template_payload_no_longer_lists_section_designs(): void
    {
        $this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk()->assertJsonMissingPath('sectionDesigns');
    }

    public function test_official_fixed_content_follows_the_reference_pdf(): void
    {
        $this->format()->assertOk();
        $fields = FieldDefinition::query()->where('plantilla_id', $this->template->id)->get()->keyBy('clave');
        $text = fn (string $key): string => FreeText::plainText($fields[$key]->contenido);

        foreach (['INDICADORES', 'PRIMER PARCIAL', 'SEGUNDO PARCIAL', 'PONDERACIÓN', '2.5 PUNTOS', '1.25', '3.5 PUNTOS', '1.75', '10 PUNTOS', '5 PUNTOS'] as $expected) {
            $this->assertStringContainsString($expected, $text('indicadores_evaluacion'));
        }
        foreach (['ESCALA CUALITATIVA', 'Igual a 10 o mayor o igual que 9', 'Menos de 6', 'Recuperación', 'No aprueba'] as $expected) {
            $this->assertStringContainsString($expected, $text('componentes_evaluacion'));
        }
        foreach (['Puntualidad:', 'Acceso a la Video Conferencia:', 'Registro de Asistencia:', 'Trabajo en Equipo:', 'Dentro de los estándares de comportamiento', 'Se considera inadmisible lo siguiente:', 'Las violaciones serán analizadas'] as $expected) {
            $this->assertStringContainsString($expected, $text('conducta_etica'));
        }
        foreach (['Elaborado por', 'Revisado y aprobado por', 'Bibliografía verificada por', 'Fecha de entrega:', 'Fecha de revisión:'] as $expected) {
            $this->assertStringContainsString($expected, $text('firmas'));
        }
    }

    public function test_only_administration_resets_the_template_and_an_open_process_blocks_it(): void
    {
        foreach (['coordinador@silabos.test', 'docente@silabos.test'] as $email) {
            $this->actingAsRole($email);
            $this->format()->assertForbidden();
        }
        $this->assertCount(0, $this->template->fresh()->sections());

        $this->actingAsRole('admin@silabos.test');
        $this->openSyllabusProcess($this->template->id);
        $this->format()->assertJsonValidationErrors('process');
    }
}
