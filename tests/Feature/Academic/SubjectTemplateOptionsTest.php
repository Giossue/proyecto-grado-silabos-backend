<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsResponseKinds;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class SubjectTemplateOptionsTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase;

    private const FIXED = 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.';

    private const BASE = ['Aula', 'Laboratorio'];

    private Subject $subject;

    private Subject $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->subject = Subject::query()->firstOrFail();
        $this->other = $this->subject->replicate();
        $this->other->codigo_asignatura = 'OTRA-1';
        $this->other->nombre = 'Otra materia';
        $this->other->save();
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function placed(string $kind = 'una', array $options = self::BASE, array $settings = [], mixed $value = null): array
    {
        $node = $this->response($kind, $options, $value);
        $node['attrs'] = [...$node['attrs'], ...$settings];

        return $node;
    }

    private function row(?Subject $subject = null): ?FieldValue
    {
        return FieldValue::query()->where('asignatura_id', ($subject ?? $this->subject)->id)->where('definicion_campo_id', $this->responseField->id)->whereNull('silabo_id')->first();
    }

    /** @return array<string, mixed> */
    private function effective(Subject $subject): array
    {
        $sections = $this->asUser('coordinador@silabos.test')->getJson(route('api.v1.academic.subjects.profile.preview', $subject))->assertOk()->json('sections');
        $field = collect($sections)->flatMap(fn (array $section): array => $section['fields'])->firstWhere('id', $this->responseField->id);

        return $field['template']['content'][1]['content'][0]['attrs'];
    }

    /** @return list<string> */
    private function titles(array $attrs): array
    {
        return array_column($attrs['options'], 'title');
    }

    private function adminSets(array $slot): void
    {
        $this->asUser('admin@silabos.test')->patchJson(route('api.v1.admin.template.structure.fields.update', [SyllabusTemplate::institutional(), $this->responseField]), [
            'content' => $this->withResponses($slot),
        ])->assertOk();
        $this->responseField->refresh();
    }

    public function test_coordination_adds_removes_and_renames_the_options_of_a_template_response(): void
    {
        $this->templateWithResponseField($this->placed());

        $this->saveSetup($this->withResponses($this->placed('una', ['Aula virtual', 'Taller', 'Campo'])))->assertOk();
        $stored = $this->row()?->valor['content'][1]['content'][0]['attrs'];
        $this->assertSame(['Aula virtual', 'Taller', 'Campo'], $this->titles($stored));
        $this->assertTrue($stored['customOptions']);

        $this->saveSetup($this->withResponses($this->placed('una', ['Taller'])))->assertOk();
        $this->assertSame(['Taller'], $this->titles($this->row()?->valor['content'][1]['content'][0]['attrs']));
    }

    public function test_coordination_cannot_change_the_kind_of_a_template_response(): void
    {
        $this->templateWithResponseField($this->placed());

        $this->saveSetup($this->withResponses($this->placed('varias', ['Aula', 'Taller'])))->assertUnprocessable()
            ->assertJsonValidationErrors(["values.{$this->responseField->id}" => self::FIXED]);
        $this->assertNull($this->row());
    }

    public function test_coordination_cannot_change_the_settings_of_a_template_response(): void
    {
        $this->templateWithResponseField($this->placed('varias', self::BASE, ['maxChoices' => 2]));

        foreach ([['maxChoices' => 1], ['maxChoices' => 2, 'required' => false], ['maxChoices' => 2, 'help' => 'Otra ayuda']] as $settings) {
            $this->saveSetup($this->withResponses($this->placed('varias', ['Aula', 'Taller', 'Campo'], $settings)))->assertUnprocessable()
                ->assertJsonValidationErrors(["values.{$this->responseField->id}" => self::FIXED]);
        }
        $this->assertNull($this->row());
    }

    public function test_coordination_cannot_remove_a_template_response_nor_leave_it_without_options(): void
    {
        $this->templateWithResponseField($this->placed());
        $removed = $this->withResponses();
        $removed['content'][1]['content'] = [];

        $this->saveSetup($removed)->assertUnprocessable()->assertJsonValidationErrors(["values.{$this->responseField->id}" => self::FIXED]);
        $this->saveSetup($this->withResponses($this->placed('una', [])))->assertUnprocessable()
            ->assertJsonValidationErrors(["values.{$this->responseField->id}" => 'Deje al menos una opción en la respuesta de la plantilla.']);
        $this->assertNull($this->row());
    }

    public function test_the_options_cannot_be_fewer_than_how_many_the_template_asks_to_choose(): void
    {
        $this->templateWithResponseField($this->placed('varias', ['Aula', 'Laboratorio', 'Campo'], ['minChoices' => 2, 'maxChoices' => 3]));

        $this->saveSetup($this->withResponses($this->placed('varias', ['Aula'], ['minChoices' => 2, 'maxChoices' => 3])))->assertUnprocessable()
            ->assertJsonValidationErrors(["values.{$this->responseField->id}" => 'Esta respuesta pide elegir al menos 2: deje al menos 2 opciones.']);
        $this->saveSetup($this->withResponses($this->placed('varias', ['Aula', 'Taller'], ['minChoices' => 2, 'maxChoices' => 3])))->assertUnprocessable()
            ->assertJsonValidationErrors(["values.{$this->responseField->id}" => 'Esta respuesta permite elegir hasta 3: deje al menos 3 opciones.']);
        $this->assertNull($this->row());

        $this->saveSetup($this->withResponses($this->placed('varias', ['Aula', 'Taller', 'Biblioteca', 'Campo'], ['minChoices' => 2, 'maxChoices' => 3])))->assertOk();
        $this->assertSame(['Aula', 'Taller', 'Biblioteca', 'Campo'], $this->titles($this->row()?->valor['content'][1]['content'][0]['attrs']));
    }

    public function test_the_teacher_of_the_subject_answers_with_its_options_and_another_subject_keeps_the_base(): void
    {
        $template = $this->templateWithResponseField($this->placed());
        $this->fillSubjectProfiles();
        $this->saveSetup($this->withResponses($this->placed('una', ['Aula', 'Laboratorio', 'Campo'])), subject: $this->subject)->assertOk();

        $this->assertSame(['Aula', 'Laboratorio', 'Campo'], $this->titles($this->effective($this->subject)));
        $this->assertSame(self::BASE, $this->titles($this->effective($this->other)));
        $this->assertNull($this->row($this->other));
        $this->other->delete();

        $this->asUser('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $syllabus = Syllabus::query()->firstOrFail();
        $teacher = $syllabus->teachers()->firstOrFail();
        $this->asUser($teacher->correo_electronico)->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $answer = fn (mixed $value): array => $this->withResponses($this->placed('una', ['Aula', 'Laboratorio', 'Campo'], [], $value));

        $this->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $this->responseField]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $answer('o3'),
        ])->assertOk();
        $value = FieldValue::query()->where('silabo_id', $syllabus->id)->where('definicion_campo_id', $this->responseField->id)->firstOrFail()->valor;
        $this->assertSame('o3', $value['content'][1]['content'][0]['attrs']['value']);

        $this->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $this->responseField]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->withResponses($this->placed('una', self::BASE, [], 'o1')),
        ])->assertUnprocessable();
    }

    public function test_a_base_change_of_the_administrator_reaches_only_the_subjects_that_did_not_customize(): void
    {
        $this->templateWithResponseField($this->placed());
        $ownText = $this->withResponses($this->placed(), ['type' => 'text', 'text' => 'Exposición']);
        $this->saveSetup($ownText, subject: $this->other)->assertOk();
        $this->assertArrayNotHasKey('customOptions', $this->row($this->other)?->valor['content'][1]['content'][0]['attrs']);
        $this->saveSetup($this->withResponses($this->placed('una', ['Taller', 'Campo'])), subject: $this->subject)->assertOk();

        $this->adminSets($this->placed('una', ['Presencial', 'Virtual', 'Híbrida']));

        $this->assertSame(['Taller', 'Campo'], $this->titles($this->effective($this->subject)));
        $this->assertSame(['Presencial', 'Virtual', 'Híbrida'], $this->titles($this->effective($this->other)));
        $profile = $this->asUser('coordinador@silabos.test')->getJson(route('api.v1.academic.subjects.profile.show', $this->other))->assertOk();
        $this->assertSame(['Presencial', 'Virtual', 'Híbrida'], $this->titles($profile->json("values.{$this->responseField->id}.content.1.content.0.attrs")));
        $this->assertSame('Exposición', $profile->json("values.{$this->responseField->id}.content.2.content.1.content.0.content.0.content.0.text"));
    }

    public function test_restoring_the_template_options_drops_the_customization_and_follows_the_administrator_again(): void
    {
        $this->templateWithResponseField($this->placed());
        $this->saveSetup($this->withResponses($this->placed('una', ['Taller'])))->assertOk();
        $this->assertNotNull($this->row());

        $this->saveSetup($this->withResponses($this->placed()))->assertOk();
        $this->assertNull($this->row());

        $withText = $this->withResponses($this->placed(), ['type' => 'text', 'text' => 'Exposición']);
        $this->saveSetup($withText)->assertOk();
        $this->assertArrayNotHasKey('customOptions', $this->row()?->valor['content'][1]['content'][0]['attrs']);
        $this->adminSets($this->placed('una', ['Presencial', 'Virtual']));
        $this->assertSame(['Presencial', 'Virtual'], $this->titles($this->effective($this->subject)));
    }

    public function test_the_responses_coordination_places_itself_stay_fully_editable_and_unmarked(): void
    {
        $this->templateWithResponseField($this->placed());
        $own = fn (string $kind, array $options): array => $this->withResponses(null, $this->response($kind, $options));

        $this->saveSetup($own('una', ['Clase', 'Taller']))->assertOk();
        $this->saveSetup($own('varias', ['Lectura']))->assertOk();
        $this->saveSetup($own('texto', []))->assertOk();
        $cell = $this->row()?->valor['content'][2]['content'][1]['content'][0]['content'][0]['content'][0]['attrs'];
        $this->assertEquals(['kind' => 'texto', 'options' => [], 'value' => ''], $cell);

        $marked = $own('una', ['Clase']);
        $marked['content'][2]['content'][1]['content'][0]['content'][0]['content'][0]['attrs']['customOptions'] = true;
        $this->saveSetup($marked)->assertOk();
        $this->assertArrayNotHasKey('customOptions', $this->row()?->valor['content'][2]['content'][1]['content'][0]['content'][0]['content'][0]['attrs']);
    }
}
