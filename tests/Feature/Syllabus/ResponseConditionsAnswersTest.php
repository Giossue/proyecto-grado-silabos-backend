<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
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

class ResponseConditionsAnswersTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase, SignsStatements;

    private const CONDITION = ['response' => 'usa_lab', 'options' => ['o1']];

    private Syllabus $syllabus;

    private FieldDefinition $techniques;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
        $template = $this->templateWithResponseField($this->source());
        $content = $this->responseField->contenido;
        $content['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Laboratorio usado: '], $this->target()]];
        $this->responseField->update(['contenido' => $content]);
        $template->saveDefinition($template->definition()->withFieldConditions(['tecnicas_ensenanza' => self::CONDITION]));
        $this->techniques = FieldDefinition::query()->where('clave', 'tecnicas_ensenanza')->firstOrFail();
        $this->fillSubjectProfiles();
    }

    private function draft(): void
    {
        $template = SyllabusTemplate::institutional();
        $this->asUser('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $this->syllabus = Syllabus::query()->firstOrFail();
        $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.start', $this->syllabus))->assertSuccessful();
        foreach ($this->teacherFields() as $field) {
            if ($field->is($this->responseField) || $field->is($this->techniques)) {
                continue;
            }
            $this->save($field, $this->answeredValue($field, "Contenido académico {$field->clave}"))->assertOk();
        }
    }

    /** @return array<string, mixed> */
    private function source(mixed $value = null): array
    {
        $node = $this->response('una', ['Sí', 'No'], $value);
        $node['attrs']['id'] = 'usa_lab';

        return $node;
    }

    /** @return array<string, mixed> */
    private function target(string $value = ''): array
    {
        return ['type' => 'response', 'attrs' => ['kind' => 'texto', 'options' => [], 'value' => $value, 'showIf' => self::CONDITION]];
    }

    /** @return array<string, mixed> */
    private function methods(?string $choice, string $detail = ''): array
    {
        $document = $this->withResponses($this->source($choice), ['type' => 'text', 'text' => 'Taller'], ['type' => 'text', 'text' => 'Cuatro'], $this->responseField->fresh()->contenido);
        $document['content'][3]['content'][1] = $this->target($detail);

        return $document;
    }

    /** @param array<string, mixed> $value */
    private function save(FieldDefinition $field, array $value): TestResponse
    {
        return $this->asUser('docente@silabos.test')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$this->syllabus, $field]), [
            'version_bloqueo' => $this->syllabus->fresh()->version_bloqueo,
            'value' => $value,
        ]);
    }

    /** @return list<string> */
    private function issueCodes(FieldDefinition $field): array
    {
        $results = $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.validate', $this->syllabus))->assertSuccessful()->json('validation.results');

        return array_values(array_column(array_filter($results, fn (array $issue): bool => $issue['field_id'] === $field->id), 'code'));
    }

    /** @return array<string, mixed> */
    private function stored(FieldDefinition $field): array
    {
        return FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $field->id)->firstOrFail()->valor;
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

    private function wordXml(): string
    {
        $docx = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'X-1', academicPeriod: 'P', revisionNumber: 1, revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-11-16T10:00:00-05:00', locale: 'es-EC', snapshot: SyllabusRevision::query()->firstOrFail()->fotografia,
        ))->docx;
        $path = (string) tempnam(sys_get_temp_dir(), 'silabos-condiciones-');
        file_put_contents($path, $docx->bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    public function test_the_teacher_payload_carries_the_conditions_of_fields_and_responses(): void
    {
        $this->draft();
        $sections = $this->asUser('docente@silabos.test')->getJson(route('api.v1.teacher.syllabi.show', $this->syllabus))->assertOk()->json('syllabus.sections');
        $fields = collect($sections)->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');

        $this->assertSame(self::CONDITION, $fields['tecnicas_ensenanza']['show_if']);
        $this->assertArrayNotHasKey('show_if', $fields['metodologia']);
        $this->assertSame('usa_lab', $fields['metodologia']['template']['content'][1]['content'][0]['attrs']['id']);
        $this->assertSame(self::CONDITION, $fields['metodologia']['template']['content'][3]['content'][1]['attrs']['showIf']);
    }

    public function test_hidden_parts_are_not_required_nor_validated_when_sending(): void
    {
        $this->draft();
        $this->save($this->responseField, $this->methods('o2'))->assertOk();

        $this->assertSame([], $this->issueCodes($this->responseField));
        $this->assertSame([], $this->issueCodes($this->techniques));
        $this->submit()->assertSuccessful();
    }

    public function test_shown_parts_stay_required(): void
    {
        $this->draft();
        $this->save($this->responseField, $this->methods('o1'))->assertOk();

        $this->assertSame(['respuesta_faltante'], $this->issueCodes($this->responseField));
        $this->assertSame(['campo_obligatorio_faltante'], $this->issueCodes($this->techniques));
        $this->submit()->assertUnprocessable();
    }

    public function test_answers_of_hidden_parts_are_kept_and_come_back_when_shown_again(): void
    {
        $this->draft();
        $long = str_repeat('Prácticas guiadas en el laboratorio de química. ', 20);
        $this->save($this->responseField, $this->methods('o1', 'Química'))->assertOk();
        $this->save($this->techniques, $this->answeredValue($this->techniques, $long))->assertOk();

        $response = $this->save($this->responseField, $this->methods('o2', 'Química'))->assertOk();
        $this->assertArrayNotHasKey('cleared', $response->json());
        $this->assertSame('Química', $this->stored($this->responseField)['content'][3]['content'][1]['attrs']['value']);
        $this->assertStringContainsString(trim($long), (string) json_encode($this->stored($this->techniques), JSON_UNESCAPED_UNICODE));

        $this->save($this->responseField, $this->methods('o1', 'Química'))->assertOk();
        $fields = collect($this->asUser('docente@silabos.test')->getJson(route('api.v1.teacher.syllabi.show', $this->syllabus))->assertOk()->json('syllabus.sections'))
            ->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertSame('Química', $fields['metodologia']['value']['content'][3]['content'][1]['attrs']['value']);
        $this->assertStringContainsString(trim($long), (string) json_encode($fields['tecnicas_ensenanza']['value'], JSON_UNESCAPED_UNICODE));

        $this->save($this->responseField, $this->methods('o2', 'Química'))->assertOk();
        $this->submit()->assertSuccessful();
        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;
        $this->assertNotContains('tecnicas_ensenanza', collect($snapshot['sections'])->flatMap(fn (array $section): array => $section['fields'])->pluck('key')->all());
        $this->assertStringNotContainsString('Química', (string) json_encode($snapshot['sections'], JSON_UNESCAPED_UNICODE));
        $xml = $this->wordXml();
        $this->assertStringNotContainsString('Prácticas guiadas', $xml);
        $this->assertStringNotContainsString('Laboratorio usado: Química', $xml);
        $this->assertSame('Química', $this->stored($this->responseField)['content'][3]['content'][1]['attrs']['value']);
        $this->assertStringContainsString(trim($long), (string) json_encode($this->stored($this->techniques), JSON_UNESCAPED_UNICODE));
    }

    public function test_hidden_parts_are_not_printed(): void
    {
        $this->draft();
        $this->save($this->responseField, $this->methods('o2'))->assertOk();
        $this->submit()->assertSuccessful();

        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;
        $keys = collect($snapshot['sections'])->flatMap(fn (array $section): array => $section['fields'])->pluck('key')->all();
        $this->assertContains('metodologia', $keys);
        $this->assertNotContains('tecnicas_ensenanza', $keys);
        $xml = $this->wordXml();
        $this->assertStringContainsString('Métodos de enseñanza', $xml);
        $this->assertStringNotContainsString('Técnicas de enseñanza', $xml);
        $this->assertStringNotContainsString('Laboratorio usado', $xml);
        $this->assertStringNotContainsString('Laboratorio usado', (string) json_encode($snapshot['sections'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Laboratorio usado', (string) json_encode($this->stored($this->responseField), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_shown_response_keeps_its_line_in_the_document(): void
    {
        $this->draft();
        $this->save($this->responseField, $this->methods('o1', 'Química'))->assertOk();
        $this->save($this->techniques, $this->answeredValue($this->techniques, 'Prácticas guiadas'))->assertOk();
        $this->submit()->assertSuccessful();

        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;
        $this->assertStringContainsString('Laboratorio usado', (string) json_encode($snapshot['sections'], JSON_UNESCAPED_UNICODE));
        $xml = $this->wordXml();
        $this->assertStringContainsString('Laboratorio usado', $xml);
        $this->assertStringContainsString('Química', $xml);
    }

    public function test_coordination_cannot_remove_an_option_that_a_condition_uses(): void
    {
        $document = $this->responseField->fresh()->contenido;
        $document['content'][1]['content'][0]['attrs']['options'] = [['const' => 'o2', 'title' => 'No'], ['const' => 'o3', 'title' => 'A veces']];

        $this->saveSetup($document)->assertUnprocessable()->assertJsonValidationErrors([
            "values.{$this->responseField->id}" => 'No puede quitar una opción que la plantilla usa para mostrar u ocultar otra parte del sílabo. Déjela o pida a Administración que cambie esa condición.',
        ]);

        $document['content'][1]['content'][0]['attrs']['options'] = [['const' => 'o1', 'title' => 'Sí, siempre'], ['const' => 'o2', 'title' => 'No']];
        $this->saveSetup($document)->assertSuccessful();
    }

    public function test_a_template_without_conditions_still_sends_every_field(): void
    {
        $this->draft();
        $template = SyllabusTemplate::institutional();
        $template->saveDefinition($template->definition()->withFieldConditions([]));
        $this->assertArrayNotHasKey('field_conditions', $template->fresh()->definition()->toArray());

        $this->save($this->responseField, $this->methods('o2'))->assertOk();

        $this->assertSame(['campo_obligatorio_faltante'], $this->issueCodes($this->techniques));
    }
}
