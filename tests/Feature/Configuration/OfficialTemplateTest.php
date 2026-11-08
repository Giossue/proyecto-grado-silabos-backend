<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Application\Actions\ProtectTemplateResponses;
use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
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
        $this->assertSame(['coordinacion' => 6, 'docente' => 9, 'institucional' => 4], $fields->countBy('quien_llena')->sortKeys()->all());
        $this->assertSame(OfficialTemplate::sectionKeys(), $sections->sortBy('posicion')->pluck('clave')->values()->all());
        $this->assertSame(array_column(OfficialTemplate::sections(), 'title'), $sections->sortBy('posicion')->pluck('titulo')->values()->all());
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.formateada']);
    }

    public function test_unit_names_kept_in_the_profile_return_to_the_teacher_keeping_ids_and_profiles(): void
    {
        $this->format()->assertOk();
        $template = $this->template->fresh();
        $units = FieldDefinition::query()->where('plantilla_id', $template->id)->get()->firstOrFail(fn (FieldDefinition $field): bool => $field->clave === 'unidades');
        $official = $template->definition()->toArray();
        $planning = $official['blocks']['unidades'];
        unset($planning['value']['units']['item']['fields']['nombre'], $planning['value']['units']['item']['fields']['resultado']);
        $template->saveDefinition(TemplateDefinition::trusted([...$official, 'blocks' => [...$official['blocks'], 'planificacion_semanal' => $planning]]));
        $units->update(['contenido' => ['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => ['kind' => 'planificacion_semanal', 'props' => [], 'value' => []]]]]]);
        $ids = FieldDefinition::query()->where('plantilla_id', $template->id)->pluck('id')->sort()->values()->all();
        $blockId = (string) Str::uuid();
        $template->appendBlock($units->block->seccion_plantilla_id, ['id' => $blockId, 'clave' => 'unidades_curriculares', 'titulo' => 'Unidades y resultados de aprendizaje', 'posicion' => 99]);
        FieldDefinition::query()->create(['plantilla_id' => $template->id, 'campo_plantilla_id' => $blockId, 'quien_llena' => FieldDefinition::COORDINATION, 'contenido' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
        app(TemplateContext::class)->forget();

        $this->assertTrue(app(ProtectTemplateResponses::class)->execute($template->fresh())['updated']);

        app(TemplateContext::class)->forget();
        $definition = $template->fresh()->definition();
        $this->assertArrayNotHasKey('planificacion_semanal', $definition->blocks());
        $this->assertArrayNotHasKey('from', $definition->blocks()['unidades']['value']['units']['items']['properties']['nombre']);
        $this->assertNull(FieldDefinition::query()->where('plantilla_id', $template->id)->get()->first(fn (FieldDefinition $field): bool => $field->clave === 'unidades_curriculares'));
        $this->assertSame('unidades', $units->fresh()->contenido['content'][0]['attrs']['kind']);
        $this->assertCount(4, $units->fresh()->contenido['content'][0]['attrs']['value']['units']);
        $this->assertSame($ids, FieldDefinition::query()->where('plantilla_id', $template->id)->pluck('id')->sort()->values()->all());
        $this->assertFalse(app(ProtectTemplateResponses::class)->execute($template->fresh())['updated']);
    }

    public function test_the_administrator_sees_the_teacher_form_of_the_saved_template_and_nobody_else_does(): void
    {
        $this->format()->assertSuccessful();

        $sections = $this->getJson(route('api.v1.admin.template.teacher-preview', $this->template))->assertOk()
            ->assertJsonStructure(['sections' => [['id', 'title', 'fields' => [['id', 'label', 'quien_llena', 'teacher_editable', 'required', 'value', 'document']]]]])
            ->json('sections');
        $this->assertCount($this->template->sections()->count(), $sections);
        $fields = collect($sections)->flatMap(fn (array $section): array => $section['fields']);
        $this->assertSame(FieldDefinition::query()->count(), $fields->count());
        $this->assertTrue($fields->contains(fn (array $field): bool => $field['quien_llena'] === 'docente' && $field['teacher_editable'] === true));

        $this->actingAsRole('docente@silabos.test');
        $this->getJson(route('api.v1.admin.template.teacher-preview', $this->template))->assertForbidden();
    }

    public function test_the_template_can_be_reset_to_the_official_format_after_editing_it(): void
    {
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'libre', 'title' => 'Mi sección'])->assertCreated();
        $this->format()->assertOk();
        $this->format()->assertOk();

        $template = $this->template->fresh();
        $this->assertCount(12, $template->sections());
        $this->assertNull($template->sections()->firstWhere('clave', 'libre'));
        $this->assertSame(19, FieldDefinition::query()->where('plantilla_id', $template->id)->count());
    }

    public function test_the_document_title_can_be_changed_with_the_template(): void
    {
        $file = json_decode((string) file_get_contents(base_path(OfficialTemplate::PATH)), true, 512, JSON_THROW_ON_ERROR);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$file, 'title' => '  SÍLABO UEB  '])->assertOk();

        $this->assertSame('SÍLABO UEB', $this->getJson(route('api.v1.admin.template.show', $this->template))->json('template.titleBlock.text'));
    }

    public function test_the_administrator_sets_how_many_units_and_weeks_the_teacher_can_use(): void
    {
        $file = json_decode((string) file_get_contents(base_path(OfficialTemplate::PATH)), true, 512, JSON_THROW_ON_ERROR);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $file)->assertOk();
        $sections = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->json('sections');
        $lists = [
            ['block' => 'unidades', 'path' => 'units', 'min' => 3, 'max' => 6, 'initial' => 3],
            ['block' => 'unidades', 'path' => 'units.*.semanas', 'min' => 1, 'max' => 20, 'initial' => 2],
        ];

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['version' => 1, 'sections' => $sections, 'lists' => $lists])->assertOk();

        $units = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->json('blocks.unidades.value.units');
        $this->assertSame([3, 6, 3], [$units['minItems'], $units['maxItems'], $units['initial']]);
        $this->assertSame([1, 20, 2], [$units['items']['properties']['semanas']['minItems'], $units['items']['properties']['semanas']['maxItems'], $units['items']['properties']['semanas']['initial']]);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['version' => 1, 'sections' => $sections, 'lists' => [[...$lists[0], 'min' => 7]]])
            ->assertUnprocessable()->assertJsonPath('errors.definition.0', '«unidad»: el máximo debe ser al menos 1 y no menor que el mínimo.');
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['version' => 1, 'sections' => $sections, 'lists' => [[...$lists[0], 'initial' => 8]]])
            ->assertUnprocessable()->assertJsonPath('errors.definition.0', '«unidad»: «Al empezar» debe estar entre el mínimo y el máximo.');
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['version' => 1, 'sections' => $sections, 'lists' => [[...$lists[0], 'path' => 'units.*.nombre']]])
            ->assertUnprocessable()->assertJsonValidationErrors('definition');
    }

    public function test_margins_and_sizes_travel_in_the_json_and_the_rest_of_the_design_stays_institutional(): void
    {
        $file = json_decode((string) file_get_contents(base_path(OfficialTemplate::PATH)), true, 512, JSON_THROW_ON_ERROR);
        $layout = ['margin_cm' => 2.5, 'title_font_size' => 16, 'section_font_size' => 12, 'body_font_size' => 10];

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$file, 'layout' => $layout])->assertOk();

        $show = $this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk();
        $show->assertJsonPath('template.layout', $layout)
            ->assertJsonPath('template.appearance.margin_cm', 2.5)
            ->assertJsonPath('template.appearance.font_family', 'Arial')
            ->assertJsonPath('template.layoutOptions.margins.0.label', '1,5 cm');
        $exported = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->json('document');
        $this->assertSame([2.5, ['title' => 16, 'section' => 12, 'body' => 10]], [$exported['margin_cm'], $exported['sizes']]);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$file, 'layout' => ['margin_cm' => 9, 'body_font_size' => 30]])
            ->assertUnprocessable()->assertJsonValidationErrors(['layout.margin_cm', 'layout.body_font_size']);
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), [...$file, 'version' => 1, 'layout' => ['font_family' => 'Georgia', 'body_font_size' => 12]])->assertOk();
        $this->getJson(route('api.v1.admin.template.show', $this->template))
            ->assertJsonPath('template.layout.body_font_size', 12)
            ->assertJsonPath('template.layout.margin_cm', 2.5)
            ->assertJsonPath('template.appearance.font_family', 'Arial');
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
                    ['key' => 'a', 'label' => 'Campo A', 'quien_llena' => 'docente', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola']]]]]],
                    ['key' => 'b', 'label' => '', 'quien_llena' => 'institucional', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]],
                ],
            ]],
        ], $override);
    }

    public function test_the_template_exports_as_a_single_json_and_imports_it_back_identically(): void
    {
        $this->format()->assertOk();
        $exported = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $this->assertSame(2, $exported['version']);
        $this->assertSame(['$schema', 'version', 'response_policy', 'document', 'variables', 'blocks', 'required_sections', 'library_sections', 'presets', 'sections'], array_keys($exported));
        $this->assertSame(route('template.schema'), $exported['$schema']);
        $this->assertMatchesTemplateSchema($exported);
        $this->assertSame(['identificacion', 'unidades', 'evaluacion', 'bibliografia'], array_keys($exported['blocks']));
        $this->assertCount(12, $exported['sections']);
        $this->assertSame(['key', 'title', 'orientation', 'fields'], array_keys($exported['sections'][0]));
        $this->assertSame(['key', 'label', 'quien_llena', 'content'], array_keys($exported['sections'][0]['fields'][0]));

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $exported)->assertOk();
        $again = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $this->assertEquals($exported, $again);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.importada']);
    }

    public function test_importing_the_template_again_keeps_the_subject_profiles(): void
    {
        $this->format()->assertOk();
        $this->fillSubjectProfiles();
        $subjects = Subject::query()->pluck('id')->all();
        $profiles = app(SubjectProfileFields::class);
        $this->assertNotContains(false, $profiles->completeBySubject($subjects));
        $count = FieldValue::query()->whereNotNull('asignatura_id')->count();

        $exported = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $exported)->assertOk();

        $this->assertNotContains(false, $profiles->completeBySubject($subjects));
        $this->assertSame($count, FieldValue::query()->whereNotNull('asignatura_id')->count());
        $this->assertSame(0, FieldValue::query()->whereNotNull('asignatura_id')->whereDoesntHave('field')->count());
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
        $this->assertSame('institucional', $fields['b']->quien_llena);
    }

    public function test_the_content_can_be_written_as_html_or_as_a_list_of_html_lines(): void
    {
        $bad = $this->sample();
        $bad['sections'][0]['fields'][0]['content'] = '<p>Texto con <strong>negrita</strong> y <em>cursiva</em>.</p><ul><li>Uno</li><li>Dos</li></ul>';
        $bad['sections'][0]['fields'][1]['content'] = ['<table>', '<tr><th colspan="2" style="background:#C3D4EA;font-weight:bold">Título</th></tr>', '<tr><td>A</td><td><span data-variable="nombre_carrera"></span></td></tr>', '</table>'];

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $bad)->assertOk();

        $fields = FieldDefinition::query()->where('plantilla_id', $this->template->id)->get()->keyBy('clave');
        $paragraph = $fields['a']->contenido['content'][0];
        $this->assertSame(['Texto con ', 'negrita', ' y ', 'cursiva', '.'], array_column($paragraph['content'], 'text'));
        $this->assertSame('bold', $paragraph['content'][1]['marks'][0]['type']);
        $this->assertSame('bulletList', $fields['a']->contenido['content'][1]['type']);
        $table = $fields['b']->contenido['content'][0];
        $this->assertSame(2, $table['content'][0]['content'][0]['attrs']['colspan']);
        $this->assertSame('#C3D4EA', $table['content'][0]['content'][0]['attrs']['backgroundColor']);
        $this->assertSame('variable', $table['content'][1]['content'][1]['content'][0]['content'][0]['type']);
    }

    public function test_the_json_export_writes_the_content_as_html_lines(): void
    {
        $this->format()->assertOk();
        $exported = $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();

        $content = $exported['sections'][0]['fields'][0]['content'];
        $this->assertIsArray($content);
        $this->assertStringStartsWith('<div data-block="identificacion"', $content[0]);
        $this->assertStringEndsWith('</div>', end($content));
    }

    public function test_an_unknown_variable_or_empty_html_content_is_reported_with_its_path(): void
    {
        $bad = $this->sample();
        $bad['sections'][0]['fields'][0]['content'] = '<p><span data-variable="no_existe"></span></p>';

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $bad)->assertJsonValidationErrors('sections.0.fields.0.content');
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

    public function test_the_payload_offers_the_two_ready_made_templates(): void
    {
        $presets = collect($this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk()->json('presets'));

        $this->assertSame(['identificacion', 'planificacion', 'bibliografia_basica', 'bibliografia_complementaria', 'firmas'], $presets->pluck('key')->all());
        $this->assertSame(['Identificación institucional', 'Distribución y planificación de las unidades curriculares', 'Bibliografía básica', 'Bibliografía complementaria', 'Firmas de revisión y aprobación'], $presets->pluck('label')->all());
        $this->assertSame(['docente', 'docente', 'docente', 'docente', 'institucional'], $presets->pluck('quien_llena')->all());
        $this->assertSame(['asignatura', 'unidades', 'bibliografia', 'bibliografia_complementaria', 'firmas'], $presets->pluck('field_key')->all());
        $this->assertSame('documentBlock', $presets->firstWhere('key', 'identificacion')['content']['content'][0]['type']);
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

        $this->assertSame('evaluacion', $fields['indicadores_evaluacion']->contenido['content'][0]['attrs']['kind']);
        $this->assertSame('unidades', $fields['unidades']->contenido['content'][0]['attrs']['kind']);
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

    public function test_the_official_template_follows_the_published_schema(): void
    {
        $official = json_decode((string) file_get_contents(resource_path('templates/silabo-oficial.json')), true);
        $this->assertMatchesTemplateSchema($official);
        $official['blocks']['unidades']['value']['units']['type'] = 'list';
        $this->assertFalse($this->templateSchemaResult($official)->isValid());
        $this->get(route('template.schema'))->assertOk()->assertHeader('Content-Type', 'application/schema+json');
    }

    /** @param  array<string, mixed>  $template */
    private function assertMatchesTemplateSchema(array $template): void
    {
        $result = $this->templateSchemaResult($template);

        $this->assertTrue($result->isValid(), $result->hasError() ? json_encode((new ErrorFormatter)->format($result->error()), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '');
    }

    /** @param  array<string, mixed>  $template */
    private function templateSchemaResult(array $template): ValidationResult
    {
        $validator = new Validator;
        $validator->resolver()?->registerFile('https://silabos.ueb/plantilla.schema.json', resource_path('templates/plantilla.schema.json'));

        return $validator->validate(Helper::toJSON($template), 'https://silabos.ueb/plantilla.schema.json');
    }
}
