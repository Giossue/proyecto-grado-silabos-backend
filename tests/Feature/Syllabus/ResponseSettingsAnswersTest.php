<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Domain\FreeText;
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

class ResponseSettingsAnswersTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase, SignsStatements;

    private const FIXED = 'Complete solo los espacios de respuesta. El texto, los encabezados y la estructura los define Administración.';

    private const PLACES = ['Aula', 'Laboratorio', 'Campo'];

    private Syllabus $syllabus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function slot(string $kind, array $settings, mixed $value = null): array
    {
        $node = $this->response($kind, $kind === 'texto' ? [] : self::PLACES, $value);
        $node['attrs'] = [...$node['attrs'], ...$settings];

        return $node;
    }

    /** @param array<string, mixed> $slot */
    private function draft(array $slot): void
    {
        $template = $this->templateWithResponseField($slot);
        $this->fillSubjectProfiles();
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

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function filled(array $slot): array
    {
        return $this->withResponses($slot, ['type' => 'text', 'text' => 'Taller'], ['type' => 'text', 'text' => 'Cuatro']);
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

    private function wordXml(): string
    {
        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;
        $docx = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'X-1', academicPeriod: 'P', revisionNumber: 1, revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-11-16T10:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        ))->docx;
        $path = (string) tempnam(sys_get_temp_dir(), 'silabos-ajustes-');
        file_put_contents($path, $docx->bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    /** @return array<string, mixed> */
    private function snapshotValue(): array
    {
        $snapshot = SyllabusRevision::query()->firstOrFail()->fotografia;

        return collect($snapshot['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'metodologia')['value'];
    }

    public function test_a_required_response_of_the_administrator_left_empty_blocks_sending(): void
    {
        $this->draft($this->slot('una', ['display' => 'desplegable']));
        $name = $this->responseField->displayName();

        $this->answer($this->filled($this->slot('una', ['display' => 'desplegable'])))->assertOk();

        $this->assertSame([['field_id' => $this->responseField->id, 'code' => 'respuesta_faltante', 'severity' => 'error', 'message' => "«{$name}» tiene una respuesta sin contestar. Elija una opción o escriba la respuesta antes de enviar."]], $this->issues());
        $this->submit()->assertUnprocessable();
        $this->assertSame(0, SyllabusRevision::query()->count());
    }

    public function test_an_answered_dropdown_prints_as_text_in_word(): void
    {
        $this->draft($this->slot('una', ['display' => 'desplegable']));

        $this->answer($this->filled($this->slot('una', ['display' => 'desplegable'], 'o2')))->assertOk();
        $this->assertSame([], $this->issues());
        $this->submit()->assertSuccessful();

        $this->assertSame("Estrategias de la asignatura\nLaboratorio\nTécnica | Horas\nTaller | Cuatro", FreeText::plainText($this->snapshotValue()));
        $this->assertMatchesRegularExpression('/Estrategias de la asignatura<\/w:t>.*>Laboratorio<\/w:t>.*Taller<\/w:t>/s', $this->wordXml());
    }

    public function test_an_optional_response_left_empty_does_not_block_and_prints_nothing(): void
    {
        $optional = $this->slot('varias', ['required' => false, 'help' => 'Si aplica.']);
        $this->draft($optional);

        $this->answer($this->filled($optional))->assertOk();
        $this->assertSame([], $this->issues());
        $this->submit()->assertSuccessful();

        $this->assertSame("Estrategias de la asignatura\n\nTécnica | Horas\nTaller | Cuatro", FreeText::plainText($this->snapshotValue()));
        $xml = $this->wordXml();
        $this->assertStringContainsString('Taller', $xml);
        $this->assertStringNotContainsString('Laboratorio', $xml);
    }

    public function test_a_field_whose_only_space_is_optional_can_be_sent_empty(): void
    {
        $optional = $this->slot('texto', ['required' => false]);
        $this->draft($optional);
        $only = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Observaciones: '], $optional]]]];
        $this->responseField->update(['contenido' => $only]);
        FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $this->responseField->id)->update(['valor' => json_encode($only)]);

        $this->assertSame([], $this->issues());

        $required = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Observaciones: '], $this->slot('texto', [])]]]];
        $this->responseField->update(['contenido' => $required]);
        FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $this->responseField->id)->update(['valor' => json_encode($required)]);
        $this->assertSame(['campo_obligatorio_faltante'], array_column($this->issues(), 'code'));
    }

    public function test_the_limits_are_checked_when_saving(): void
    {
        $limited = $this->slot('varias', ['minChoices' => 2, 'maxChoices' => 2]);
        $this->draft($limited);

        $this->answer($this->filled($this->slot('varias', ['minChoices' => 2, 'maxChoices' => 2], ['o1'])))->assertUnprocessable()->assertJsonValidationErrors(['value' => 'Elija al menos 2 opciones.']);
        $this->answer($this->filled($this->slot('varias', ['minChoices' => 2, 'maxChoices' => 2], ['o1', 'o2', 'o3'])))->assertUnprocessable()->assertJsonValidationErrors(['value' => 'Elija como máximo 2 opciones.']);
        $this->answer($this->filled($this->slot('varias', ['minChoices' => 2, 'maxChoices' => 2], ['o1', 'o2'])))->assertOk();
        $this->assertSame([], $this->issues());
    }

    public function test_the_text_limit_is_checked_when_saving_and_when_sending(): void
    {
        $limited = $this->slot('texto', ['maxLength' => 5]);
        $this->draft($limited);
        $name = $this->responseField->displayName();

        $this->answer($this->filled($this->slot('texto', ['maxLength' => 5], 'seis l')))->assertUnprocessable()->assertJsonValidationErrors(['value' => 'Escriba una respuesta de hasta 5 caracteres.']);
        $this->answer($this->filled($this->slot('texto', ['maxLength' => 5], 'cinco')))->assertOk();

        $stored = FieldValue::query()->where('silabo_id', $this->syllabus->id)->where('definicion_campo_id', $this->responseField->id)->firstOrFail();
        $value = $stored->valor;
        $value['content'][1]['content'][0]['attrs']['value'] = 'demasiado largo';
        FieldValue::query()->whereKey($stored->getKey())->update(['valor' => json_encode($value)]);

        $this->assertContains(['field_id' => $this->responseField->id, 'code' => 'respuesta_fuera_de_limite', 'severity' => 'error', 'message' => "«{$name}»: Escriba una respuesta de hasta 5 caracteres."], $this->issues());
    }

    public function test_the_teacher_cannot_change_the_settings(): void
    {
        $this->draft($this->slot('una', []));

        $this->answer($this->filled($this->slot('una', ['required' => false])))->assertUnprocessable()->assertJsonValidationErrors(['value' => self::FIXED]);
        $this->answer($this->filled($this->slot('una', ['display' => 'desplegable'], 'o1')))->assertUnprocessable()->assertJsonValidationErrors(['value' => self::FIXED]);
        $this->answer($this->filled($this->slot('una', [], 'o1')))->assertOk();
    }
}
