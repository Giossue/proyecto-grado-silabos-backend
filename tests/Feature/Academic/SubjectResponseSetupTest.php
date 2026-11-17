<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsResponseKinds;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class SubjectResponseSetupTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase;

    private const FIXED = 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.';

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $this->subject = Subject::query()->firstOrFail();
    }

    private function setupRow(): ?FieldValue
    {
        return FieldValue::query()->where('asignatura_id', $this->subject->id)->where('definicion_campo_id', $this->responseField->id)->first();
    }

    public function test_the_career_project_shows_each_career_field_once_with_its_template(): void
    {
        $this->templateWithResponseField();
        $profile = $this->asUser('coordinador@silabos.test')->getJson(route('api.v1.academic.subjects.profile.show', $this->subject))->assertOk();

        $field = collect($profile->json('fields'))->firstWhere('key', 'metodologia');
        $this->assertSame('carrera', $field['quien_llena']);
        $this->assertSame('Estrategias de la asignatura', $field['template']['content'][0]['content'][0]['text']);
        $this->assertNotContains('bibliografia', array_column($profile->json('fields'), 'key'));
        $this->assertContains('descripcion', array_column($profile->json('fields'), 'key'));
        $positions = array_column($profile->json('fields'), 'position');
        $this->assertSame(count($positions), count(array_unique($positions)));
    }

    public function test_coordination_turns_a_slot_and_a_table_cell_into_a_response_and_it_is_kept_as_json_of_the_subject(): void
    {
        $this->templateWithResponseField();
        $setup = $this->withResponses($this->response('texto', [], '7'), $this->response('una', ['Clase magistral', 'Taller'], 'o1'));

        $saved = $this->saveSetup($setup)->assertOk();

        $stored = $this->setupRow()?->valor;
        $this->assertSame('texto', $stored['content'][1]['content'][0]['attrs']['kind']);
        $this->assertSame('', $stored['content'][1]['content'][0]['attrs']['value']);
        $cell = $stored['content'][2]['content'][1]['content'][0]['content'][0]['content'][0]['attrs'];
        $this->assertEquals(['kind' => 'una', 'options' => [['const' => 'o1', 'title' => 'Clase magistral'], ['const' => 'o2', 'title' => 'Taller']], 'value' => null], $cell);
        $this->assertEquals($stored, $saved->json("values.{$this->responseField->id}"));
        $this->assertNull($this->setupRow()?->silabo_id);
        $this->assertSame(0, FieldValue::query()->whereNotNull('silabo_id')->count());

        $this->saveSetup($this->withResponses())->assertOk();
        $this->assertNull($this->setupRow());
    }

    public function test_only_a_choice_without_options_keeps_the_career_project_incomplete(): void
    {
        $this->templateWithResponseField($this->response('una'));
        $this->asUser('coordinador@silabos.test')->getJson(route('api.v1.academic.subjects.profile.show', $this->subject))->assertOk()->assertJsonPath('complete', false);

        $this->saveSetup($this->withResponses($this->response('una', ['A', 'B'])))->assertOk()->assertJsonPath('complete', true);
        $this->saveSetup($this->withResponses($this->response('una'), $this->response('varias')))->assertOk()->assertJsonPath('complete', false);
    }

    public function test_coordination_writes_in_the_spaces_but_cannot_change_the_fixed_format(): void
    {
        $this->templateWithResponseField();
        $base = $this->withResponses();
        $text = [['type' => 'text', 'text' => 'Texto de Coordinación']];
        $changedText = $base;
        $changedText['content'][0]['content'][0]['text'] = 'Otro texto';
        $changedHeader = $base;
        $changedHeader['content'][2]['content'][0]['content'][0]['content'][0]['content'][0]['text'] = 'Otra columna';
        $addedRow = $base;
        $addedRow['content'][2]['content'][] = $base['content'][2]['content'][1];
        $filledSlot = $base;
        $filledSlot['content'][1]['content'] = $text;
        $filledCell = $base;
        $filledCell['content'][2]['content'][1]['content'][0]['content'][0]['content'] = $text;
        $textNextToResponse = $this->withResponses();
        $textNextToResponse['content'][1]['content'] = [...$text, $this->response('texto')];

        foreach ([$changedText, $changedHeader] as $document) {
            $this->saveSetup($document)->assertUnprocessable()->assertJsonValidationErrors(["values.{$this->responseField->id}" => self::FIXED]);
        }
        $this->assertNull($this->setupRow());
        foreach ([$addedRow, $filledSlot, $filledCell, $textNextToResponse] as $document) {
            $this->saveSetup($document)->assertOk();
            $this->assertNotNull($this->setupRow());
        }
    }

    public function test_coordination_writes_the_options_the_administrator_left_empty_and_adjusts_the_ones_already_written(): void
    {
        $this->templateWithResponseField($this->response('una'));
        $this->saveSetup($this->withResponses($this->response('una', ['Sí', 'No'])))->assertOk();
        $this->assertSame('Sí', $this->setupRow()?->valor['content'][1]['content'][0]['attrs']['options'][0]['title']);
        $this->saveSetup($this->withResponses($this->response('varias', ['Sí', 'No'])))->assertUnprocessable();
        $this->saveSetup($this->withResponses($this->response('texto')))->assertUnprocessable();

        $this->asUser('admin@silabos.test')->patchJson(route('api.v1.admin.template.structure.fields.update', [SyllabusTemplate::institutional(), $this->responseField]), [
            'content' => $this->withResponses($this->response('una', ['Presencial', 'Virtual'])),
        ])->assertOk();
        $this->responseField->refresh();
        $this->saveSetup($this->withResponses($this->response('una', ['Presencial', 'Otra'])))->assertOk();
        $this->assertTrue($this->setupRow()?->valor['content'][1]['content'][0]['attrs']['customOptions']);
        $this->saveSetup($this->withResponses($this->response('varias', ['Presencial', 'Virtual'])))->assertUnprocessable()
            ->assertJsonValidationErrors(["values.{$this->responseField->id}" => self::FIXED]);
    }

    public function test_an_open_convocation_locks_the_response_kinds_like_the_rest_of_the_career_project(): void
    {
        $template = $this->templateWithResponseField();
        $this->fillSubjectProfiles();
        $this->saveSetup($this->withResponses($this->response('texto')))->assertOk();
        $this->asUser('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id)->id,
        ])->assertSuccessful();

        $this->saveSetup($this->withResponses($this->response('una', ['A'])))->assertUnprocessable()->assertJsonValidationErrors('process');
        $this->assertSame('texto', $this->setupRow()?->valor['content'][1]['content'][0]['attrs']['kind']);
    }

    public function test_only_the_career_of_the_subject_builds_its_responses(): void
    {
        $this->templateWithResponseField();
        $this->asUser('docente@silabos.test')->putJson(route('api.v1.academic.subjects.profile.update', $this->subject), [
            'values' => [$this->responseField->id => $this->withResponses($this->response('texto'))],
        ])->assertForbidden();
        $this->assertNull($this->setupRow());
    }

    public function test_coordination_answers_the_responses_the_administrator_placed(): void
    {
        $this->templateWithResponseField($this->response('una', ['Básica', 'Profesional']), 'descripcion');
        $this->fillSubjectProfiles();
        $this->assertSame('carrera', $this->responseField->quien_llena);
        $fields = collect($this->asUser('coordinador@silabos.test')->getJson(route('api.v1.academic.subjects.profile.show', $this->subject))->assertOk()->assertJsonPath('complete', true)->json('fields'));
        $this->assertSame('una', $fields->firstWhere('key', 'descripcion')['template']['content'][1]['content'][0]['attrs']['kind']);

        $answer = fn (mixed $value, array $options = ['Básica', 'Profesional']): array => $this->withResponses($this->response('una', $options, $value), base: $this->filledTemplateValue($this->responseField, 'Texto de Coordinación'));
        $this->saveSetup($answer('o2'))->assertOk()->assertJsonPath('complete', true);
        $this->assertSame('o2', $this->setupRow()?->valor['content'][1]['content'][0]['attrs']['value']);
        $this->saveSetup($answer('o1', ['Básica', 'Otra']))->assertOk();
        $this->assertSame(['Básica', 'Otra'], array_column($this->setupRow()?->valor['content'][1]['content'][0]['attrs']['options'], 'title'));
        $this->saveSetup($answer('o9'))->assertUnprocessable()->assertJsonValidationErrors(["values.{$this->responseField->id}" => 'Elija solo entre las opciones de la respuesta.']);
    }

    public function test_replacing_the_template_keeps_what_coordination_built_in_the_same_field(): void
    {
        $template = $this->templateWithResponseField();
        $this->saveSetup($this->withResponses($this->response('texto')))->assertOk();
        $previous = $this->responseField->id;

        $json = $this->asUser('admin@silabos.test')->getJson(route('api.v1.admin.template.json.export', $template))->assertOk()->json();
        $this->putJson(route('api.v1.admin.template.json.import', $template), $json)->assertSuccessful();

        $this->responseField = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
        $this->assertNotSame($previous, $this->responseField->id);
        $this->asUser('coordinador@silabos.test')->getJson(route('api.v1.academic.subjects.profile.show', $this->subject))->assertOk()
            ->assertJsonPath("values.{$this->responseField->id}.content.1.content.0.attrs.kind", 'texto');
    }

    public function test_a_field_without_responses_is_saved_and_protected_as_before(): void
    {
        $this->templateWithResponseField(null, 'descripcion');
        $this->saveSetup($this->filledTemplateValue($this->responseField, 'Descripción de la materia'))->assertOk();
        $this->assertStringContainsString('Descripción de la materia', json_encode($this->setupRow()?->valor, JSON_UNESCAPED_UNICODE));

        $changed = $this->filledTemplateValue($this->responseField, 'Descripción de la materia');
        $changed['content'][0]['content'][0]['text'] = 'Otro texto fijo';
        $this->saveSetup($changed)->assertUnprocessable()
            ->assertJsonValidationErrors(["values.{$this->responseField->id}" => 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.']);
        $this->assertSame(1, FieldDefinition::query()->where('clave', 'descripcion')->count());
    }
}
