<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsResponseKinds;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\TestCase;

class ResponseKindAnswersTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase, SignsStatements;

    private const FIXED = 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.';

    private const TECHNIQUES = ['Clase magistral', 'Taller'];

    private Syllabus $syllabus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
    }

    /**
     * @param  (\Closure(): array<string, mixed>)|null  $setup
     * @param  array<string, mixed>|null  $adminSlot
     */
    private function draft(?\Closure $setup, ?array $adminSlot = null): void
    {
        $template = $this->templateWithResponseField($adminSlot);
        $this->fillSubjectProfiles();
        if ($setup !== null) {
            $this->saveSetup($setup())->assertOk();
        }
        $this->asUser('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $this->syllabus = Syllabus::query()->firstOrFail();
        $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.start', $this->syllabus))->assertSuccessful();
        foreach ($this->teacherFields() as $field) {
            if ($field->is($this->responseField)) {
                continue;
            }
            $this->patchJson(route('api.v1.teacher.syllabi.fields.update', [$this->syllabus, $field]), [
                'version_bloqueo' => $this->syllabus->fresh()->version_bloqueo,
                'value' => $this->answeredValue($field, "Contenido académico {$field->clave}"),
            ])->assertOk();
        }
    }

    /** @return array<string, mixed> */
    private function built(): array
    {
        return $this->withResponses($this->response('texto'), $this->response('una', self::TECHNIQUES), $this->response('varias', ['Aula', 'Laboratorio', 'Campo']));
    }

    /** @return array<string, mixed> */
    private function answers(mixed $text = '12', mixed $technique = 'o2', mixed $places = ['o3', 'o1']): array
    {
        return $this->withResponses($this->response('texto', [], $text), $this->response('una', self::TECHNIQUES, $technique), $this->response('varias', ['Aula', 'Laboratorio', 'Campo'], $places));
    }

    /** @param array<string, mixed> $document */
    private function answer(array $document): TestResponse
    {
        return $this->asUser('docente@silabos.test')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$this->syllabus, $this->responseField]), [
            'version_bloqueo' => $this->syllabus->fresh()->version_bloqueo,
            'value' => $document,
        ]);
    }

    /** @return array<string, mixed> */
    private function stored(?Syllabus $syllabus = null): array
    {
        return FieldValue::query()->where('silabo_id', ($syllabus ?? $this->syllabus)->id)->where('definicion_campo_id', $this->responseField->id)->firstOrFail()->valor;
    }

    /** @return list<array{field_id: string|null, code: string, severity: string, message: string}> */
    private function issues(): array
    {
        $results = $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.validate', $this->syllabus))->assertSuccessful()->json('validation.results');

        return array_values(array_filter($results, fn (array $issue): bool => $issue['field_id'] === $this->responseField->id));
    }

    private function submit(): TestResponse
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        return $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.submit', $this->syllabus), [
            'signature' => $this->teacherSignature($this->syllabus->fresh(), $teacher),
            'version_bloqueo' => $this->syllabus->fresh()->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_the_syllabus_starts_from_what_coordination_built_and_the_teacher_only_answers(): void
    {
        $this->draft(fn (): array => $this->built());

        $this->assertEquals(FieldDocument::normalize($this->built()), $this->stored());
        $payload = $this->asUser('docente@silabos.test')->getJson(route('api.v1.teacher.syllabi.show', $this->syllabus))->assertOk();
        $field = collect($payload->json('syllabus.sections'))->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia');
        $this->assertEquals(FieldDocument::normalize($this->built()), $field['template']);

        $this->answer($this->answers())->assertOk();
        $this->assertSame('12', $this->stored()['content'][1]['content'][0]['attrs']['value']);
        $this->assertSame('o2', $this->stored()['content'][2]['content'][1]['content'][0]['content'][0]['content'][0]['attrs']['value']);
        $this->assertSame(['o3', 'o1'], $this->stored()['content'][2]['content'][1]['content'][1]['content'][0]['content'][0]['attrs']['value']);

        $twoRows = $this->answers();
        $twoRows['content'][2]['content'][] = $this->answers(technique: 'o1', places: ['o2'])['content'][2]['content'][1];
        $this->answer($twoRows)->assertOk();
        $this->assertCount(3, $this->stored()['content'][2]['content']);
    }

    public function test_the_teacher_cannot_write_edit_the_options_nor_remove_a_response(): void
    {
        $this->draft(fn (): array => $this->built());
        $this->answer($this->answers())->assertOk();
        $text = [['type' => 'text', 'text' => 'Escrito a mano']];
        $otherOptions = $this->withResponses(technique: $this->response('una', ['Clase magistral', 'Inventada'], 'o2'), base: $this->answers());
        $otherKind = $this->withResponses($this->response('una', ['A'], 'o1'), base: $this->answers());
        $written = $this->answers();
        $written['content'][1]['content'] = $text;
        $writtenBeside = $this->answers();
        $writtenBeside['content'][1]['content'][] = $text[0];
        $removed = $this->answers();
        $removed['content'][2]['content'][1]['content'][0]['content'][0]['content'] = [];

        foreach ([$otherOptions, $otherKind, $written, $writtenBeside, $removed] as $document) {
            $this->answer($document)->assertUnprocessable()->assertJsonValidationErrors(['value' => self::FIXED]);
        }
        $this->answer($this->answers(technique: ['o1', 'o2']))->assertUnprocessable()->assertJsonValidationErrors(['value' => 'Elija solo entre las opciones de la respuesta.']);
        $this->answer($this->answers(places: ['o1', 'o9']))->assertUnprocessable();
        $this->assertEquals($this->answers(), $this->stored());
    }

    public function test_an_unanswered_response_blocks_sending_with_a_clear_message(): void
    {
        $this->draft(fn (): array => $this->built());
        $name = $this->responseField->displayName();
        $pending = ['field_id' => $this->responseField->id, 'code' => 'respuesta_faltante', 'severity' => 'error', 'message' => "«{$name}» tiene una respuesta sin contestar. Elija una opción o escriba la respuesta antes de enviar."];

        $this->assertSame(['campo_obligatorio_faltante'], array_column($this->issues(), 'code'));
        foreach ([$this->answers(text: ''), $this->answers(technique: null), $this->answers(places: [])] as $document) {
            $this->answer($document)->assertOk();
            $this->assertSame([$pending], $this->issues());
        }
        $this->submit()->assertUnprocessable();
        $this->assertSame(0, SyllabusRevision::query()->count());

        $this->answer($this->answers())->assertOk();
        $this->assertSame([], $this->issues());
        $this->submit()->assertSuccessful();
    }

    public function test_a_choice_without_written_options_asks_for_the_coordinator(): void
    {
        $this->draft(null);
        $this->responseField->update(['contenido' => $this->withResponses($this->response('una'))]);
        FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $this->responseField->id)
            ->update(['valor' => json_encode($this->withResponses($this->response('una')))]);
        $name = $this->responseField->displayName();

        $this->assertContains(
            ['field_id' => $this->responseField->id, 'code' => 'respuesta_sin_opciones', 'severity' => 'error', 'message' => "«{$name}» tiene una respuesta sin opciones para elegir: pídalas al coordinador."],
            $this->issues(),
        );
        $this->answer($this->withResponses($this->response('una', [], 'o1')))->assertUnprocessable();
        $this->submit()->assertUnprocessable();
        $this->assertSame(0, SyllabusRevision::query()->count());
    }

    public function test_the_sent_revision_keeps_what_was_chosen_and_the_word_prints_it_as_text(): void
    {
        $this->draft(fn (): array => $this->built());
        $this->answer($this->answers())->assertOk();
        $this->submit()->assertSuccessful();
        FieldValue::query()->whereNotNull('asignatura_id')->where('definicion_campo_id', $this->responseField->id)
            ->update(['valor' => json_encode($this->withResponses($this->response('texto'), $this->response('una', ['Otra cosa'])))]);

        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;
        $field = collect($snapshot['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia');
        $this->assertSame("Estrategias de la asignatura\n12\nTécnica | Horas\nTaller | Campo\nAula", FreeText::plainText($field['value']));

        $docx = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'X-1', academicPeriod: 'P', revisionNumber: 1, revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-11-10T10:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        ))->docx;
        $path = tempnam(sys_get_temp_dir(), 'silabos-respuestas-');
        file_put_contents($path, $docx->bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);
        $this->assertMatchesRegularExpression('/Estrategias de la asignatura<\/w:t>.*>12<\/w:t>.*Taller<\/w:t>.*Campo<\/w:t>.*<w:br\/>.*Aula<\/w:t>/s', $xml);
        $this->assertStringNotContainsString('Otra cosa', $xml);
        $this->assertStringNotContainsString('Laboratorio', $xml);
    }

    public function test_changing_the_responses_with_syllabi_in_progress_keeps_what_the_teacher_already_answered(): void
    {
        $this->draft(fn (): array => $this->withResponses(technique: $this->response('una', self::TECHNIQUES)));
        $hours = [['type' => 'text', 'text' => 'Cuatro horas']];
        $answered = $this->withResponses(technique: $this->response('una', self::TECHNIQUES, 'o2'));
        $answered['content'][2]['content'][1]['content'][1]['content'][0]['content'] = $hours;
        $this->answer($answered)->assertOk();
        $convocation = Convocation::query()->firstOrFail();
        $this->asUser('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))->assertSuccessful();

        $rebuilt = $this->withResponses($this->response('texto'), $this->response('una', [...self::TECHNIQUES, 'Seminario']));
        $this->saveSetup($rebuilt)->assertOk();

        $draft = $this->stored();
        $this->assertSame('borrador', $this->syllabus->fresh()->estado);
        $this->assertEquals(['kind' => 'texto', 'options' => [], 'value' => ''], $draft['content'][1]['content'][0]['attrs']);
        $technique = $draft['content'][2]['content'][1]['content'][0]['content'][0]['content'][0]['attrs'];
        $this->assertSame('o2', $technique['value']);
        $this->assertSame('Seminario', $technique['options'][2]['title']);
        $this->assertEquals($hours, $draft['content'][2]['content'][1]['content'][1]['content'][0]['content']);

        Syllabus::query()->whereKey($this->syllabus->id)->update(['estado' => 'aprobado']);
        $this->saveSetup($this->withResponses($this->response('texto'), $this->response('una', ['Taller'])))->assertUnprocessable()->assertJsonValidationErrors('purge_required');
        $this->assertSame('aprobado', $this->syllabus->fresh()->estado);
        $this->saveSetup($this->withResponses($this->response('texto'), $this->response('una', ['Taller'])), ['confirm_purge' => true])->assertOk();
        $this->assertSame('borrador', $this->syllabus->fresh()->estado);
        $this->assertNull($this->stored()['content'][2]['content'][1]['content'][0]['content'][0]['content'][0]['attrs']['value']);
        $this->assertEquals($hours, $this->stored()['content'][2]['content'][1]['content'][1]['content'][0]['content']);
    }

    public function test_a_field_without_responses_keeps_its_free_answers_and_its_messages(): void
    {
        $this->draft(null);
        $name = $this->responseField->displayName();
        $this->assertSame([['field_id' => $this->responseField->id, 'code' => 'campo_obligatorio_faltante', 'severity' => 'error', 'message' => "«{$name}» está vacío. Complétalo antes de enviar."]], $this->issues());

        $this->answer($this->filledTemplateValue($this->responseField, 'Respuesta escrita'))->assertOk();
        $this->assertSame([], $this->issues());
        $changed = $this->filledTemplateValue($this->responseField, 'Respuesta escrita');
        $changed['content'][0]['content'][0]['text'] = 'Otro texto fijo';
        $this->answer($changed)->assertUnprocessable()->assertJsonValidationErrors(['value' => self::FIXED]);
        $forged = $this->withResponses($this->response('texto', [], '3'));
        $this->answer($forged)->assertUnprocessable()->assertJsonValidationErrors(['value' => self::FIXED]);
    }

    public function test_the_own_options_of_the_subject_reach_the_teacher_without_the_customization_marker(): void
    {
        $own = fn (mixed $value = null): array => $this->withResponses($this->response('una', ['Aula', 'Laboratorio', 'Campo'], $value));
        $this->draft(fn (): array => $own(), $this->response('una', ['Aula', 'Laboratorio']));

        $attrs = $this->stored()['content'][1]['content'][0]['attrs'];
        $this->assertSame(['Aula', 'Laboratorio', 'Campo'], array_column($attrs['options'], 'title'));
        $this->assertArrayNotHasKey('customOptions', $attrs);
        $payload = $this->asUser('docente@silabos.test')->getJson(route('api.v1.teacher.syllabi.show', $this->syllabus))->assertOk();
        $field = collect($payload->json('syllabus.sections'))->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia');
        $this->assertArrayNotHasKey('customOptions', $field['template']['content'][1]['content'][0]['attrs']);

        $markedDocument = $own('o3');
        $markedDocument['content'][1]['content'][0]['attrs']['customOptions'] = true;
        $this->answer($markedDocument)->assertOk();
        $attrs = $this->stored()['content'][1]['content'][0]['attrs'];
        $this->assertSame('o3', $attrs['value']);
        $this->assertArrayNotHasKey('customOptions', $attrs);

        $row = FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $this->responseField->id)->firstOrFail();
        $row->forceFill(['valor' => $markedDocument])->save();
        $this->assertSame([], $this->issues());
        $this->submit()->assertSuccessful();
        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;
        $sent = collect($snapshot['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia');
        $this->assertSame('o3', $sent['document']['content'][1]['content'][0]['attrs']['value']);
        $this->assertArrayNotHasKey('customOptions', $sent['document']['content'][1]['content'][0]['attrs']);
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function closedSetup(array $options): array
    {
        return $this->withResponses($this->response('una', $options, 'o2'), ['type' => 'text', 'text' => 'Exposición'], ['type' => 'text', 'text' => '4']);
    }

    private function approvedClosedField(): void
    {
        $this->draft(fn (): array => $this->closedSetup(['Aula', 'Laboratorio', 'Campo']), $this->response('una', ['Aula', 'Laboratorio']));
        $attrs = $this->stored()['content'][1]['content'][0]['attrs'];
        $this->assertSame(['Aula', 'Laboratorio', 'Campo'], array_column($attrs['options'], 'title'));
        $this->assertArrayNotHasKey('customOptions', $attrs);
        $this->syllabus->forceFill(['estado' => 'aprobado'])->save();
    }

    private function catchUp(): void
    {
        app(InProgressWork::class)->catchUp([(string) $this->syllabus->convocation->carrera_id]);
    }

    public function test_restoring_the_template_options_of_a_closed_field_sends_the_approved_syllabus_back_on_catch_up(): void
    {
        $this->approvedClosedField();
        FieldValue::query()->whereNotNull('asignatura_id')->where('definicion_campo_id', $this->responseField->id)
            ->firstOrFail()->forceFill(['valor' => FieldResponse::career($this->responseField->contenido, $this->closedSetup(['Aula', 'Laboratorio']))])->save();

        $this->assertSame([(string) $this->syllabus->id], app(InProgressWork::class)->returningToDraft([$this->syllabus->fresh()]));
        $this->catchUp();

        $this->assertSame('borrador', $this->syllabus->fresh()->estado);
        $attrs = $this->stored()['content'][1]['content'][0]['attrs'];
        $this->assertSame(['Aula', 'Laboratorio'], array_column($attrs['options'], 'title'));
        $this->assertSame('o2', $attrs['value']);
    }

    public function test_a_closed_field_with_own_options_and_nothing_changed_keeps_the_approved_syllabus_on_catch_up(): void
    {
        $this->approvedClosedField();
        $this->assertSame([], app(InProgressWork::class)->returningToDraft([$this->syllabus->fresh()]));

        $row = FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $this->responseField->id)->firstOrFail();
        $legacy = $row->valor;
        $legacy['content'][1]['content'][0]['attrs']['customOptions'] = true;
        $row->forceFill(['valor' => $legacy])->save();
        $this->assertSame([], app(InProgressWork::class)->returningToDraft([$this->syllabus->fresh()]));

        $this->catchUp();
        $this->assertSame('aprobado', $this->syllabus->fresh()->estado);
    }
}
