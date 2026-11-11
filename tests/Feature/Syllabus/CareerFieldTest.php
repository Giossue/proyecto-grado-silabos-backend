<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Domain\FreeText;
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

class CareerFieldTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase, SignsStatements;

    private const FIXED = 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.';

    private Syllabus $syllabus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->asUser('admin@silabos.test')->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $this->postJson(route('api.v1.admin.template.format', SyllabusTemplate::institutional()))->assertSuccessful();
        $this->responseField = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
    }

    public function test_a_career_field_written_entirely_by_coordination_reaches_the_teacher_fixed(): void
    {
        $written = $this->document(
            $this->paragraph('Métodos activos de la carrera.'),
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->paragraph('Clase invertida')]]]],
            ['type' => 'paragraph'],
        );
        $this->draft($written);

        $this->assertCount(2, $this->profileRow()['content']);
        $field = $this->teacherView();
        $this->assertSame('carrera', $field['quien_llena']);
        $this->assertFalse($field['teacher_editable']);
        $this->assertCount(2, $field['template']['content']);
        $this->answer($this->document($this->paragraph('Cambio del docente')))->assertUnprocessable()->assertJsonValidationErrors('field');
        $this->assertSame([], $this->issues());
        $this->submit()->assertSuccessful();
        $this->assertStringContainsString('Clase invertida', FreeText::plainText($this->snapshotValue()));
    }

    public function test_a_career_field_left_as_the_template_is_written_entirely_by_the_teacher(): void
    {
        $this->draft(null);

        $field = $this->teacherView();
        $this->assertTrue($field['teacher_editable']);
        $name = $this->responseField->displayName();
        $this->assertSame([['field_id' => $this->responseField->id, 'code' => 'campo_obligatorio_faltante', 'severity' => 'error', 'message' => "«{$name}» está vacío. Complétalo antes de enviar."]], $this->issues());
        $this->answer($this->document($this->paragraph('Aprendizaje basado en problemas'), ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [$this->paragraph('Casos')]]]]))->assertOk();
        $this->assertSame([], $this->issues());
    }

    public function test_coordination_text_and_a_choice_list_let_the_teacher_only_pick(): void
    {
        $choice = fn (mixed $value = null): array => $this->document(['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Método principal: '],
            $this->response('una', ['Taller', 'Seminario'], $value),
        ]]);
        $this->draft($choice());

        $this->assertTrue($this->teacherView()['teacher_editable']);
        $this->submit()->assertUnprocessable();
        $changed = $choice('o2');
        $changed['content'][0]['content'][0]['text'] = 'Otro método: ';
        $this->answer($changed)->assertUnprocessable()->assertJsonValidationErrors(['value' => self::FIXED]);
        $this->answer($choice('o2'))->assertOk();
        $this->assertSame([], $this->issues());
        $this->submit()->assertSuccessful();
        $this->assertSame('Método principal: Seminario', FreeText::plainText($this->snapshotValue()));
    }

    public function test_a_free_text_space_after_the_coordination_text_is_answered_and_printed_as_text(): void
    {
        $space = fn (mixed $value = null): array => $this->document(
            $this->paragraph('Lecturas sugeridas por la carrera.'),
            ['type' => 'paragraph', 'content' => [$this->response('texto', [], $value)]],
        );
        $this->draft($space());

        $name = $this->responseField->displayName();
        $this->answer($space('Lectura del docente'))->assertOk();
        $this->answer($space(''))->assertOk();
        $this->assertSame([['field_id' => $this->responseField->id, 'code' => 'campo_obligatorio_faltante', 'severity' => 'error', 'message' => "«{$name}» está vacío. Complétalo antes de enviar."]], $this->issues());
        $this->answer($space("Primera lectura\nSegunda lectura"))->assertOk();
        $this->submit()->assertSuccessful();

        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;
        $docx = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'X-1', academicPeriod: 'P', revisionNumber: 1, revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-11-10T10:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        ))->docx;
        $path = tempnam(sys_get_temp_dir(), 'silabos-carrera-');
        file_put_contents($path, $docx->bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);
        $this->assertMatchesRegularExpression('/Lecturas sugeridas por la carrera\.<\/w:t>.*Primera lectura<\/w:t>.*<w:br\/>.*Segunda lectura<\/w:t>/s', $xml);
        $this->assertStringNotContainsString('data-response', $xml);
    }

    public function test_the_spaces_coordination_leaves_reach_the_teacher_unanswered(): void
    {
        $this->asUser('coordinador@silabos.test');
        $this->saveSetup($this->document(
            $this->paragraph('Texto de la carrera.'),
            ['type' => 'paragraph', 'content' => [$this->response('una', ['Taller', 'Seminario'], 'o1'), $this->response('texto', [], '4')]],
        ))->assertOk();

        $row = $this->profileRow();
        $this->assertNull($row['content'][1]['content'][0]['attrs']['value']);
        $this->assertSame('', $row['content'][1]['content'][1]['attrs']['value']);
    }

    public function test_the_administrator_chooses_only_between_institutional_and_career(): void
    {
        $template = SyllabusTemplate::institutional();
        $section = $template->sections->first();
        foreach (['docente', 'coordinacion'] as $retired) {
            $this->asUser('admin@silabos.test')->postJson(route('api.v1.admin.template.structure.fields.store', [$template, $section->id]), [
                'key' => "retirado_{$retired}", 'label' => 'Retirado', 'quien_llena' => $retired, 'content' => FreeText::fromText(''),
            ])->assertUnprocessable()->assertJsonValidationErrors('quien_llena');
        }
        $this->postJson(route('api.v1.admin.template.structure.fields.store', [$template, $section->id]), [
            'key' => 'vinculacion', 'label' => 'Vinculación', 'quien_llena' => 'carrera', 'content' => FreeText::fromText(''),
        ])->assertCreated()->assertJsonPath('field.quien_llena', 'carrera');
    }

    public function test_an_old_template_json_with_teacher_and_coordination_fields_still_imports(): void
    {
        $template = SyllabusTemplate::institutional();
        $json = $this->asUser('admin@silabos.test')->getJson(route('api.v1.admin.template.json.export', $template))->assertOk()->json();
        foreach ($json['sections'] as $index => $section) {
            foreach ($section['fields'] ?? [] as $position => $field) {
                if ($field['quien_llena'] === 'carrera') {
                    $json['sections'][$index]['fields'][$position]['quien_llena'] = $position % 2 === 0 ? 'docente' : 'coordinacion';
                }
            }
        }

        $this->putJson(route('api.v1.admin.template.json.import', $template), $json)->assertSuccessful();

        $this->assertSame(['carrera', 'institucional'], FieldDefinition::query()->get()->pluck('quien_llena')->unique()->sort()->values()->all());
    }

    /**
     * @param  array<string, mixed>|null  $written
     */
    private function draft(?array $written): void
    {
        $template = SyllabusTemplate::institutional();
        $this->fillSubjectProfiles();
        if ($written !== null) {
            $this->saveSetup($written)->assertOk();
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
    private function document(array ...$nodes): array
    {
        return ['type' => 'doc', 'content' => $nodes];
    }

    /** @return array<string, mixed> */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return array<string, mixed> */
    private function profileRow(): array
    {
        return FieldValue::query()->whereNotNull('asignatura_id')->where('definicion_campo_id', $this->responseField->id)->firstOrFail()->valor;
    }

    /** @return array<string, mixed> */
    private function teacherView(): array
    {
        $payload = $this->asUser('docente@silabos.test')->getJson(route('api.v1.teacher.syllabi.show', $this->syllabus))->assertOk();

        return collect($payload->json('syllabus.sections'))->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia');
    }

    /** @return array<string, mixed> */
    private function snapshotValue(): array
    {
        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;

        return collect($snapshot['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia')['value'];
    }

    /** @param array<string, mixed> $document */
    private function answer(array $document): TestResponse
    {
        return $this->asUser('docente@silabos.test')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$this->syllabus, $this->responseField]), [
            'version_bloqueo' => $this->syllabus->fresh()->version_bloqueo,
            'value' => $document,
        ]);
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
}
