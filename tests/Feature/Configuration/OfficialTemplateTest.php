<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Application\OfficialTemplate;
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
        $this->assertSame(OfficialTemplate::sectionKeys(), $sections->sortBy('posicion')->pluck('clave')->values()->all());
        $this->assertSame(array_column(OfficialTemplate::sections(), 'title'), $sections->sortBy('posicion')->pluck('titulo')->values()->all());
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

    public function test_the_official_json_file_is_valid_for_the_import_endpoint(): void
    {
        $file = json_decode((string) file_get_contents(base_path(OfficialTemplate::PATH)), true, 512, JSON_THROW_ON_ERROR);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $file)->assertOk();

        $this->assertSame(OfficialTemplate::sectionKeys(), $this->template->fresh()->sections()->sortBy('posicion')->pluck('clave')->values()->all());
        app(TemplateStructureValidator::class)->assertUsable($this->template->fresh());
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

    private function sample(array $override = []): array
    {
        return array_replace_recursive([
            'version' => 1,
            'sections' => [[
                'key' => 'uno', 'title' => '1. Uno', 'orientation' => 'landscape',
                'fields' => [
                    ['key' => 'a', 'label' => 'Campo A', 'help' => 'Ayuda A', 'quien_llena' => 'docente', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola']]]]]],
                    ['key' => 'b', 'label' => '', 'help' => null, 'quien_llena' => 'institucional', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]],
                ],
            ]],
        ], $override);
    }

    public function test_the_template_exports_as_a_single_json_and_imports_it_back_identically(): void
    {
        $this->format()->assertOk();
        $exported = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $this->assertSame(1, $exported['version']);
        $this->assertCount(12, $exported['sections']);
        $this->assertSame(['key', 'title', 'orientation', 'fields'], array_keys($exported['sections'][0]));
        $this->assertSame(['key', 'label', 'help', 'quien_llena', 'content'], array_keys($exported['sections'][0]['fields'][0]));

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $exported)->assertOk();
        $again = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $this->assertEquals($exported, $again);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.importada']);
    }

    public function test_the_spaces_around_formatted_text_are_kept_when_saving_a_field(): void
    {
        $this->format()->assertOk();
        $field = FieldDefinition::query()->where('plantilla_id', $this->template->id)->where('clave', 'descripcion')->firstOrFail();
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola '], ['type' => 'text', 'text' => 'mundo', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' fin ']]]]];

        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$this->template, $field]), ['content' => $document])->assertOk();

        $texts = collect($field->fresh()->contenido['content'][0]['content'])->pluck('text')->all();
        $this->assertSame(['Hola ', 'mundo', ' fin '], $texts);
    }

    public function test_importing_a_json_replaces_the_whole_template(): void
    {
        $this->format()->assertOk();
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->sample())->assertOk();

        $template = $this->template->fresh();
        $this->assertCount(1, $template->sections());
        $this->assertSame('landscape', $template->sections()->first()->orientacion);
        $fields = FieldDefinition::query()->where('plantilla_id', $template->id)->get()->keyBy('clave');
        $this->assertEqualsCanonicalizing(['a', 'b'], $fields->keys()->all());
        $this->assertSame('Ayuda A', $fields['a']->ayuda);
        $this->assertSame('institucional', $fields['b']->quien_llena);
    }

    public function test_importing_an_empty_json_leaves_a_blank_template(): void
    {
        $this->format()->assertOk();
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['version' => 1, 'sections' => []])->assertOk();

        $this->assertCount(0, $this->template->fresh()->sections());
    }

    public function test_the_json_is_validated_with_the_path_of_each_error(): void
    {
        $bad = $this->sample();
        $bad['sections'][0]['fields'][1]['key'] = 'a';
        $bad['sections'][0]['fields'][0]['quien_llena'] = 'otro';
        $bad['sections'][0]['orientation'] = 'diagonal';
        $bad['sections'][0]['fields'][0]['content'] = ['type' => 'texto'];

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $bad)->assertJsonValidationErrors([
            'sections.0.orientation', 'sections.0.fields.0.quien_llena', 'sections.0.fields.0.content', 'sections.0.fields.1.key',
        ]);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['sections' => []])->assertJsonValidationErrors('version');
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['version' => 1])->assertJsonValidationErrors('sections');
        $this->assertCount(0, $this->template->fresh()->sections());
    }

    public function test_the_json_rejects_duplicate_section_keys(): void
    {
        $bad = $this->sample();
        $bad['sections'][] = ['key' => 'uno', 'title' => 'Otra'];

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $bad)->assertJsonValidationErrors(['sections.0.key', 'sections.1.key']);
    }

    public function test_only_administration_imports_and_an_open_process_blocks_it(): void
    {
        $this->actingAsRole('coordinador@silabos.test');
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->sample())->assertForbidden();
        $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertForbidden();

        $this->actingAsRole('admin@silabos.test');
        $this->openSyllabusProcess($this->template->id);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->sample())->assertJsonValidationErrors('process');
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
