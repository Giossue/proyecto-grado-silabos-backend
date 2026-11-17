<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
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

class FieldLimitAnswersTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase, SignsStatements;

    private const CONDITION = ['response' => 'usa_lab', 'options' => ['o1']];

    private const COORDINATION = 'Nota larga que escribió Coordinación para todos los paralelos.';

    private Syllabus $syllabus;

    private SyllabusTemplate $template;

    private FieldDefinition $techniques;

    private FieldDefinition $bibliography;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->asUser('admin@silabos.test')->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $this->template = SyllabusTemplate::institutional();
        $this->postJson(route('api.v1.admin.template.format', $this->template))->assertSuccessful();
        $this->responseField = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$this->template, $this->responseField]), ['content' => $this->adminContent()])->assertOk();
        $this->responseField->refresh();
        $this->techniques = FieldDefinition::query()->where('clave', 'tecnicas_ensenanza')->firstOrFail();
        $this->bibliography = FieldDefinition::query()->where('clave', 'bibliografia')->firstOrFail();
        $this->fillSubjectProfiles();
        $built = $this->responseField->contenido;
        $built['content'][2]['content'][1]['attrs']['value'] = self::COORDINATION;
        $this->saveSetup($built)->assertOk();
    }

    /** @return array<string, mixed> */
    private function adminContent(): array
    {
        $paragraph = fn (string $text, ?array $response = null): array => ['type' => 'paragraph', 'content' => [
            ...($text === '' ? [] : [['type' => 'text', 'text' => $text]]),
            ...($response === null ? [] : [$response]),
        ]];
        $source = $this->response('una', ['Sí', 'No']);
        $source['attrs']['id'] = 'usa_lab';
        $cell = fn (string $type, string $text = ''): array => ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [
            $text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];

        return ['type' => 'doc', 'content' => [
            $paragraph('Estrategias de la asignatura'),
            $paragraph('', $source),
            $paragraph('Nota de Coordinación: ', $this->response('texto')),
            $paragraph('Laboratorio usado: ', ['type' => 'response', 'attrs' => ['kind' => 'texto', 'options' => [], 'showIf' => self::CONDITION]]),
            ['type' => 'paragraph'],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [$cell('tableHeader', 'Técnica'), $cell('tableHeader', 'Horas')]],
                ['type' => 'tableRow', 'content' => [$cell('tableCell'), $cell('tableCell')]],
            ]],
        ]];
    }

    /** @param array<string, int> $limits */
    private function limit(array $limits): void
    {
        $template = $this->template->fresh();
        $template->saveDefinition($template->definition()->withFieldLimits($limits));
    }

    private function draft(): void
    {
        $this->asUser('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($this->template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $this->syllabus = Syllabus::query()->firstOrFail();
        $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.start', $this->syllabus))->assertSuccessful();
        foreach ($this->teacherFields() as $field) {
            if ($field->is($this->responseField)) {
                continue;
            }
            $this->save($field, $this->answeredValue($field, "Contenido académico {$field->clave}"))->assertOk();
        }
    }

    /** @return array<string, mixed> */
    private function methods(string $choice = 'o1', string $detail = 'Química', string $free = 'Hola mundo', string $technique = 'Taller', string $hours = '4'): array
    {
        $text = fn (string $value): array => $value === '' ? [] : [['type' => 'text', 'text' => $value]];
        $document = FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $this->responseField->id)->firstOrFail()->valor;
        $document['content'][1]['content'][0]['attrs']['value'] = $choice;
        $document['content'][3]['content'][1]['attrs']['value'] = $detail;
        $document['content'][4]['content'] = $text($free);
        $document['content'][5]['content'][1]['content'][0]['content'][0]['content'] = $text($technique);
        $document['content'][5]['content'][1]['content'][1]['content'][0]['content'] = $text($hours);
        if ($document['content'][4]['content'] === []) {
            unset($document['content'][4]['content']);
        }

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

    /** @return array<string, mixed> */
    private function stored(FieldDefinition $field): array
    {
        return FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $field->id)->firstOrFail()->valor;
    }

    /** @return list<array{field_id: string|null, code: string, severity: string, message: string}> */
    private function issues(FieldDefinition $field): array
    {
        $results = $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.validate', $this->syllabus))->assertSuccessful()->json('validation.results');

        return array_values(array_filter($results, fn (array $issue): bool => $issue['field_id'] === $field->id));
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

    public function test_the_teacher_payload_carries_the_limit_of_each_field(): void
    {
        $this->limit(['metodologia' => 30]);
        $this->draft();

        $fields = collect($this->asUser('docente@silabos.test')->getJson(route('api.v1.teacher.syllabi.show', $this->syllabus))->assertOk()->json('syllabus.sections'))
            ->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertSame(30, $fields['metodologia']['max_length']);
        $this->assertArrayNotHasKey('max_length', $fields['tecnicas_ensenanza']);
    }

    public function test_a_whole_free_text_field_rejects_more_than_its_limit(): void
    {
        $this->limit(['tecnicas_ensenanza' => 40]);
        $this->draft();

        $this->save($this->techniques, $this->filledTemplateValue($this->techniques, str_repeat('á', 40)))->assertOk();
        $this->save($this->techniques, $this->filledTemplateValue($this->techniques, str_repeat('á', 41)))->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'Este campo admite hasta 40 caracteres.']);
        $this->assertStringContainsString(str_repeat('á', 40), (string) json_encode($this->stored($this->techniques), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString(str_repeat('á', 41), (string) json_encode($this->stored($this->techniques), JSON_UNESCAPED_UNICODE));
    }

    public function test_it_counts_free_text_and_text_answers_but_not_fixed_text_coordination_answers_nor_hidden_parts(): void
    {
        $this->limit(['metodologia' => 30]);
        $this->draft();

        $this->save($this->responseField, $this->methods())->assertOk();
        $this->save($this->responseField, $this->methods(detail: 'Química avanzada'))->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'Este campo admite hasta 30 caracteres.']);
        $this->save($this->responseField, $this->methods(technique: 'Taller práctico de campo'))->assertUnprocessable();
        $this->save($this->responseField, $this->methods(free: 'Hola mundo, con más texto'))->assertUnprocessable();
        $this->save($this->responseField, $this->methods(choice: 'o2', detail: 'Química avanzada'))->assertOk();
        $this->assertSame('Química avanzada', $this->stored($this->responseField)['content'][3]['content'][1]['attrs']['value']);
        $this->assertSame(self::COORDINATION, $this->stored($this->responseField)['content'][2]['content'][1]['attrs']['value']);
        $this->assertSame([], $this->issues($this->responseField));
    }

    public function test_repeatable_tables_do_not_count(): void
    {
        $this->limit(['bibliografia' => 1]);
        $this->draft();

        $this->save($this->bibliography, $this->answeredValue($this->bibliography, 'Un título de libro bastante largo para la prueba'))->assertOk();
        $this->assertSame([], $this->issues($this->bibliography));
    }

    public function test_text_written_before_a_new_limit_is_kept_can_be_trimmed_and_blocks_sending_until_it_fits(): void
    {
        $this->draft();
        $this->save($this->responseField, $this->methods())->assertOk();
        $this->limit(['metodologia' => 20]);
        $name = $this->responseField->displayName();
        $over = ['field_id' => $this->responseField->id, 'code' => 'campo_supera_limite', 'severity' => 'error', 'message' => "«{$name}» tiene 24 caracteres y admite hasta 20. Recorte el texto antes de enviar."];

        $this->assertSame([$over], $this->issues($this->responseField));
        $this->submit()->assertUnprocessable();
        $this->assertSame(0, SyllabusRevision::query()->count());
        $this->assertSame('Hola mundo', $this->stored($this->responseField)['content'][4]['content'][0]['text']);

        $this->save($this->responseField, $this->methods())->assertOk();
        $this->save($this->responseField, $this->methods(hours: '40'))->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'Este campo admite hasta 20 caracteres.']);
        $this->save($this->responseField, $this->methods(free: 'Hola'))->assertOk();
        $this->assertSame([], $this->issues($this->responseField));
        $this->submit()->assertSuccessful();
    }

    public function test_without_limits_saving_and_sending_work_as_before(): void
    {
        $this->draft();

        $this->save($this->responseField, $this->methods(free: str_repeat('Texto largo del docente. ', 200)))->assertOk();
        $this->assertSame([], $this->issues($this->responseField));
        $this->assertArrayNotHasKey('field_limits', $this->template->fresh()->definition()->toArray());
        $this->submit()->assertSuccessful();
    }
}
