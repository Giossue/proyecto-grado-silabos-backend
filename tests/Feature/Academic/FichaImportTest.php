<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Application\FichaWorkbookContext;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\AcademicContextSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FichaImportTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private RoleAssignment $context;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seed(DatabaseSeeder::class);
        $this->seed(SyllabusTemplateSeeder::class);
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->context = $this->coordinator->roleAssignments()->firstOrFail();
    }

    /** @param  array<string, string>  $texts */
    private function workbook(string $subjectId, bool $table = false, array $texts = []): UploadedFile
    {
        $definition = app(FichaWorkbookContext::class)->for((string) $this->context->carrera_id);
        $book = app(FichaWorkbook::class)->create($definition);
        $subjectIndex = array_search($subjectId, array_column($definition->subjects, 'id'), true);
        foreach ($definition->fields as $field) {
            $sheet = $book->getSheetByName($field['sheet']);
            $row = 4 + $subjectIndex * ($field['columns'] === [] ? 1 : 40);
            if (isset($texts[$field['key']])) {
                $sheet->setCellValue('D'.$row, $texts[$field['key']]);
            } elseif ($table && $field['key'] === 'contribucion_perfil') {
                $sheet->fromArray([['Resuelve sistemas lineales', 'X', '', '', 'Resuelve problemas matemáticos']], null, 'D'.$row);
            } elseif (! $table && in_array($field['key'], ['descripcion', 'objetivos_especificos'], true)) {
                $sheet->setCellValue('D'.$row, $field['key'] === 'descripcion' ? 'Ficha completada en Excel' : "- Analizar.\n- Aplicar.");
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'ficha-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'proyecto.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function finalizedSyllabus(Subject $subject): Syllabus
    {
        $period = AcademicPeriod::query()->firstOrFail();
        $period->update(['fecha_inicio' => now()->subDays(90), 'fecha_fin' => now()->addDays(60)]);
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => $period->id,
            'inicia_en' => now()->subDays(30),
            'entrega_en' => now()->subDay(),
            'estado' => SyllabusProcess::STATE_CLOSED,
            'finalizada_en' => now()->subHour(),
        ]);
        $convocation = Convocation::query()->create([
            'proceso_id' => $process->id,
            'carrera_id' => $this->context->carrera_id,
            'estado' => Convocation::STATE_CLOSED,
        ]);
        $convocation->forceFill(['estado_previo' => Convocation::STATE_OPEN])->save();
        $parallel = Parallel::query()->firstOrCreate(['asignatura_id' => $subject->id, 'codigo' => 'A'], ['jornada' => 'matutina']);

        return Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => app(AcademicContextSnapshot::class)->build($parallel, $period),
            'estado' => 'sin_iniciar',
        ]);
    }

    private function asCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->context->id]);

        return $this;
    }

    public function test_the_workbook_previews_a_profile_and_applies_only_the_chosen_subjects(): void
    {
        $subject = Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->firstOrFail();

        $file = $this->workbook($subject->id);
        $response = $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.spreadsheet'), ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk();
        $detail = $response->json('preview');
        $entry = collect($detail['subjects'])->firstWhere('subject_id', $subject->id);
        $this->assertSame('lista', $entry['state']);
        $this->assertSame('Ficha completada en Excel', $entry['values']['descripcion']);
        $this->assertSame(['Analizar.', 'Aplicar.'], $entry['values']['objetivos_especificos']);
        $this->assertArrayNotHasKey('objetivo_general', $entry['values']);
        $this->assertSame(2, $entry['coverage']['found']);
        $this->assertSame(11, $entry['coverage']['total']);
        $this->assertCount(9, $entry['missing']);

        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.apply'), ['file' => $file, 'fingerprint' => $detail['fingerprint'], 'file_hash' => $detail['file_hash'], 'subjects' => [$subject->id]], ['Accept' => 'application/json'])->assertOk();

        $description = FieldValue::query()->where('asignatura_id', $subject->id)
            ->whereIn('definicion_campo_id', FieldDefinition::query()->where('clave', 'descripcion')->select('definicion_campo_id'))->firstOrFail();
        $this->assertStringContainsString('Ficha completada en Excel', json_encode($description->valor, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_workbook_writes_a_career_field_that_the_teacher_used_to_fill(): void
    {
        $subject = Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->firstOrFail();
        $file = $this->workbook($subject->id, texts: ['tecnicas_ensenanza' => 'Técnicas acordadas por la carrera']);
        $detail = $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.spreadsheet'), ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('preview');

        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.apply'), ['file' => $file, 'fingerprint' => $detail['fingerprint'], 'file_hash' => $detail['file_hash'], 'subjects' => [$subject->id]], ['Accept' => 'application/json'])->assertOk();

        $field = FieldDefinition::query()->where('clave', 'tecnicas_ensenanza')->firstOrFail();
        $templates = app(SubjectProfileFields::class)->templates($subject->id);
        $this->assertSame('Técnicas acordadas por la carrera', FreeText::plainText($templates[$field->id]));
        $this->assertFalse(SubjectProfileFields::teacherEditable($field, $templates));
    }

    public function test_the_template_download_matches_the_current_fields_and_subjects(): void
    {
        $this->asCoordinator();
        ob_start();
        $this->get(route('api.v1.coordination.ficha-imports.template'))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'ficha-');
        file_put_contents($path, (string) ob_get_clean());

        $definition = app(FichaWorkbookContext::class)->for((string) $this->context->carrera_id);
        $book = IOFactory::load($path);
        $this->assertSame(
            array_column($definition->fields, 'sheet'),
            array_values(array_diff($book->getSheetNames(), ['Indicaciones', '_estructura'])),
        );
        $this->assertCount(11, $definition->fields);
        $this->assertSame(
            Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->where('genera_silabo', true)->count(),
            count($definition->subjects),
        );
        foreach ($definition->fields as $field) {
            $this->assertTrue($book->getSheetByName($field['sheet'])->getProtection()->getSheet());
        }
    }

    public function test_the_proposal_shows_the_current_profile_and_applies_only_the_chosen_fields(): void
    {
        $subject = Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->firstOrFail();
        $description = FieldDefinition::query()->where('clave', 'descripcion')->firstOrFail();
        FieldValue::query()->updateOrCreate(
            ['asignatura_id' => $subject->id, 'definicion_campo_id' => $description->id],
            ['valor' => FreeText::fromText('Descripción que ya estaba')],
        );

        $file = $this->workbook($subject->id);
        $preview = $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.spreadsheet'), ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('preview');
        $entry = collect($preview['subjects'])->firstWhere('subject_id', $subject->id);
        $this->assertTrue($entry['replaces']);
        $this->assertSame('Descripción que ya estaba', $entry['current']['descripcion']);

        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.apply'), [
            'file' => $file, 'fingerprint' => $preview['fingerprint'], 'file_hash' => $preview['file_hash'],
            'subjects' => [$subject->id],
            'fields' => [$subject->id => ['objetivos_especificos']],
        ], ['Accept' => 'application/json'])->assertOk();

        $kept = FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $description->id)->firstOrFail();
        $this->assertStringContainsString('Descripción que ya estaba', json_encode($kept->valor, JSON_UNESCAPED_UNICODE));
        $objectives = FieldDefinition::query()->where('clave', 'objetivos_especificos')->firstOrFail();
        $this->assertTrue(FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $objectives->id)->exists());
    }

    public function test_a_finalized_convocation_warns_before_applying_the_workbook_and_keeps_its_syllabi(): void
    {
        $subject = Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->firstOrFail();
        $syllabus = $this->finalizedSyllabus($subject);
        $description = FieldDefinition::query()->where('clave', 'descripcion')->firstOrFail();
        $stored = fn (): string => md5((string) json_encode([$syllabus->fresh()?->getAttributes(), FieldValue::query()->where('silabo_id', $syllabus->id)->get()->toArray()]));
        $before = $stored();

        $file = $this->workbook($subject->id);
        $preview = $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.spreadsheet'), ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('preview');
        $payload = ['file' => $file, 'fingerprint' => $preview['fingerprint'], 'file_hash' => $preview['file_hash'], 'subjects' => [$subject->id]];

        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.apply'), $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.purge_mode.0', 'convocatoria_finalizada');
        $this->assertFalse(FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $description->id)->exists());

        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.apply'), [...$payload, 'confirm_purge' => '1'], ['Accept' => 'application/json'])->assertOk();

        $this->assertTrue(FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $description->id)->exists());
        $this->assertSame($before, $stored());
    }

    public function test_only_the_career_coordinator_can_use_the_workbook(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->actingAs($teacher)->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->getJson(route('api.v1.coordination.ficha-imports.template'))->assertForbidden();
    }

    public function test_table_rows_follow_the_template_and_are_applied_as_a_table(): void
    {
        $subject = Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->firstOrFail();
        $file = $this->workbook($subject->id, true);
        $response = $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.spreadsheet'), ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $preview = $response->json('preview');
        $entry = collect($preview['subjects'])->firstWhere('subject_id', $subject->id);
        $field = FieldDefinition::query()->where('clave', 'contribucion_perfil')->firstOrFail();

        $this->assertSame(app(FichaFieldValues::class)->schema($field)['columns'], $entry['values']['contribucion_perfil']['columns']);
        $this->assertCount(5, $entry['values']['contribucion_perfil']['columns']);
        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.apply'), ['file' => $file, 'fingerprint' => $preview['fingerprint'], 'file_hash' => $preview['file_hash'], 'subjects' => [$subject->id]], ['Accept' => 'application/json'])->assertOk();

        $value = FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $field->id)->firstOrFail();
        $this->assertSame('table', $value->valor['content'][0]['type']);
        $this->assertStringContainsString('Resuelve sistemas lineales', FreeText::plainText($value->valor));
        $this->assertStringContainsString('ALTA (A)', FreeText::plainText($value->valor));
    }
}
