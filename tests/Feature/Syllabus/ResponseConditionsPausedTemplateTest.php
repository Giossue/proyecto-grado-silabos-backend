<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Syllabus\Application\SyllabusDocumentFields;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsResponseKinds;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\TestCase;

class ResponseConditionsPausedTemplateTest extends TestCase
{
    use BuildsResponseKinds, ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase, SignsStatements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
    }

    public function test_a_condition_added_while_paused_keeps_the_field_the_teacher_already_answered(): void
    {
        $template = $this->templateWithResponseField($this->response('una', ['Sí', 'No']));
        $this->fillSubjectProfiles();
        $process = $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String());
        $this->asUser('coordinador@silabos.test')->postJson(route('api.v1.coordination.convocations.store'), ['process_id' => $process->id])->assertSuccessful();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $techniques = FieldDefinition::query()->where('clave', 'tecnicas_ensenanza')->firstOrFail();
        foreach ($this->teacherFields() as $field) {
            $value = match (true) {
                $field->is($this->responseField) => $this->withResponses($this->response('una', ['Sí', 'No'], 'o1'), ['type' => 'text', 'text' => 'Taller'], ['type' => 'text', 'text' => 'Cuatro']),
                $field->is($techniques) => $this->answeredValue($field, 'Debates guiados por el docente'),
                default => $this->answeredValue($field, "Contenido académico {$field->clave}"),
            };
            $this->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $value])->assertOk();
        }

        $this->asUser('admin@silabos.test')->post(route('api.v1.admin.processes.transition', [$process, 'pausar']))->assertSuccessful();
        $json = $this->getJson(route('api.v1.admin.template.json.export', $template))->assertOk()->json();
        $methods = $this->responseField->fresh()->contenido;
        $methods['content'][1]['content'][0]['attrs']['id'] = 'usa_lab';
        foreach ($json['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                $field = match ($field['key']) {
                    'metodologia' => [...$field, 'content' => $methods],
                    'tecnicas_ensenanza' => [...$field, 'show_if' => ['response' => 'usa_lab', 'options' => ['o1']]],
                    default => $field,
                };
            }
            unset($field);
        }
        unset($section);
        $this->putJson(route('api.v1.admin.template.json.import', $template), ['version' => 1, 'title' => $json['document']['title']['text'], 'sections' => $json['sections']])->assertOk();
        $this->assertSame(['tecnicas_ensenanza' => ['response' => 'usa_lab', 'options' => ['o1']]], SyllabusTemplate::institutional()->definition()->fieldConditions());
        $this->assertNotNull(SyllabusDocumentFields::archivedSections($syllabus->fresh()));
        $this->assertTeacherFieldPrinted(app(SyllabusSnapshot::class)->build($syllabus->fresh()));
        $this->post(route('api.v1.admin.processes.transition', [SyllabusProcess::query()->firstOrFail(), 'reanudar']), ['confirmar_rehacer' => true])->assertSuccessful();

        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $syllabus = $syllabus->fresh();
        $this->asUser('docente@silabos.test')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        $this->assertTeacherFieldPrinted(SyllabusRevision::query()->firstOrFail()->fotografia);
    }

    /** @param array<string, mixed> $snapshot */
    private function assertTeacherFieldPrinted(array $snapshot): void
    {
        $fields = collect($snapshot['sections'])->flatMap(fn (array $section): array => $section['fields'])->keyBy('key');
        $this->assertTrue($fields->has('tecnicas_ensenanza'));
        $this->assertStringContainsString('Debates guiados por el docente', (string) json_encode($fields['tecnicas_ensenanza']['document'], JSON_UNESCAPED_UNICODE));
        $docx = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Materia', subjectCode: 'X-1', academicPeriod: 'P', revisionNumber: 1, revisionFingerprint: str_repeat('a', 64),
            generatedAt: '2026-11-16T10:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        ))->docx;
        $path = (string) tempnam(sys_get_temp_dir(), 'silabos-pausa-');
        file_put_contents($path, $docx->bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('Debates guiados por el docente', $xml);
    }
}
