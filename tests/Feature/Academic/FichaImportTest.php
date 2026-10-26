<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Application\FichaWorkbookContext;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Spreadsheets\FichaWorkbook;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function workbook(string $subjectId, bool $table = false): UploadedFile
    {
        $definition = app(FichaWorkbookContext::class)->for((string) $this->context->carrera_id);
        $book = app(FichaWorkbook::class)->create($definition);
        $subjectIndex = array_search($subjectId, array_column($definition->subjects, 'id'), true);
        foreach ($definition->fields as $field) {
            $sheet = $book->getSheetByName($field['sheet']);
            $row = 4 + $subjectIndex * ($field['key'] === 'unidades_curriculares' ? 4 : ($field['columns'] === [] ? 1 : 40));
            if ($table && $field['key'] === 'contribucion_perfil') {
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
        $this->assertSame(7, $entry['coverage']['total']);
        $this->assertCount(5, $entry['missing']);

        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.apply'), ['file' => $file, 'fingerprint' => $detail['fingerprint'], 'file_hash' => $detail['file_hash'], 'subjects' => [$subject->id]], ['Accept' => 'application/json'])->assertOk();

        $description = FieldValue::query()->where('asignatura_id', $subject->id)
            ->whereIn('definicion_campo_id', FieldDefinition::query()->where('clave', 'descripcion')->select('definicion_campo_id'))->firstOrFail();
        $this->assertStringContainsString('Ficha completada en Excel', json_encode($description->valor, JSON_UNESCAPED_UNICODE));
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
