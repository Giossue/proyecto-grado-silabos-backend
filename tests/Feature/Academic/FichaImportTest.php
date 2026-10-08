<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Domain\Contracts\FichaExtractionGateway;
use App\Modules\Academic\Infrastructure\Gateways\DisabledFichaExtractionGateway;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        $this->app->bind(FichaExtractionGateway::class, fn () => new class implements FichaExtractionGateway
        {
            public function available(): bool
            {
                return true;
            }

            public function propose(array $subject, array $fields, array $evidence): array
            {
                return ['descripcion' => "Ficha propuesta de {$subject['name']}", 'objetivos_especificos' => ['Analizar.', 'Aplicar.'], 'objetivo_general' => ''];
            }
        });
    }

    private function workbook(string $subjectName): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Asignatura', 'Contenido'], [mb_strtoupper($subjectName), 'Introducción a la materia']]);
        $path = tempnam(sys_get_temp_dir(), 'ficha-').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'proyecto.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function asCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->context->id]);

        return $this;
    }

    public function test_the_assistant_proposes_a_profile_from_the_documents_and_applies_only_the_chosen_subjects(): void
    {
        $subject = Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->firstOrFail();

        $response = $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.store'), ['files' => [$this->workbook($subject->nombre)]], ['Accept' => 'application/json'])
            ->assertStatus(202);
        $importId = $response->json('import.id');

        $detail = $this->asCoordinator()->getJson(route('api.v1.coordination.ficha-imports.index'))->assertOk()->json('import');
        $this->assertSame('lista', $detail['state']);
        $entry = collect($detail['subjects'])->firstWhere('subject_id', $subject->id);
        $this->assertSame('lista', $entry['state']);
        $this->assertSame("Ficha propuesta de {$subject->nombre}", $entry['values']['descripcion']);
        $this->assertSame(['Analizar.', 'Aplicar.'], $entry['values']['objetivos_especificos']);
        $this->assertArrayNotHasKey('objetivo_general', $entry['values']);
        Storage::disk('private')->assertExists("importaciones-ficha/{$importId}/bloques.json");

        $this->asCoordinator()->postJson(route('api.v1.coordination.ficha-imports.apply', $importId), ['subjects' => [$subject->id]])->assertOk();

        $description = FieldValue::query()->where('asignatura_id', $subject->id)
            ->whereIn('definicion_campo_id', FieldDefinition::query()->where('clave', 'descripcion')->select('definicion_campo_id'))->firstOrFail();
        $this->assertStringContainsString('Ficha propuesta', json_encode($description->valor, JSON_UNESCAPED_UNICODE));
        $this->assertSame('aplicada', FichaImport::query()->findOrFail($importId)->estado_importacion);
        Storage::disk('private')->assertMissing("importaciones-ficha/{$importId}/bloques.json");
    }

    public function test_the_proposal_shows_the_current_profile_and_applies_only_the_chosen_fields(): void
    {
        $subject = Subject::query()->where('carrera_id', $this->context->carrera_id)->where('activo', true)->firstOrFail();
        $description = FieldDefinition::query()->where('clave', 'descripcion')->firstOrFail();
        FieldValue::query()->updateOrCreate(
            ['asignatura_id' => $subject->id, 'definicion_campo_id' => $description->id],
            ['valor' => FreeText::fromText('Descripción que ya estaba')],
        );

        $importId = $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.store'), ['files' => [$this->workbook($subject->nombre)]], ['Accept' => 'application/json'])->json('import.id');
        $entry = collect($this->asCoordinator()->getJson(route('api.v1.coordination.ficha-imports.index'))->json('import.subjects'))->firstWhere('subject_id', $subject->id);
        $this->assertTrue($entry['replaces']);
        $this->assertSame('Descripción que ya estaba', $entry['current']['descripcion']);

        $this->asCoordinator()->postJson(route('api.v1.coordination.ficha-imports.apply', $importId), [
            'subjects' => [$subject->id],
            'fields' => [$subject->id => ['objetivos_especificos']],
        ])->assertOk();

        $kept = FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $description->id)->firstOrFail();
        $this->assertStringContainsString('Descripción que ya estaba', json_encode($kept->valor, JSON_UNESCAPED_UNICODE));
        $objectives = FieldDefinition::query()->where('clave', 'objetivos_especificos')->firstOrFail();
        $this->assertTrue(FieldValue::query()->where('asignatura_id', $subject->id)->where('definicion_campo_id', $objectives->id)->exists());
    }

    public function test_only_the_career_coordinator_can_use_the_assistant(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->actingAs($teacher)->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->getJson(route('api.v1.coordination.ficha-imports.index'))->assertForbidden();
    }

    public function test_it_refuses_to_start_without_an_ai_provider(): void
    {
        $this->app->bind(FichaExtractionGateway::class, fn () => new DisabledFichaExtractionGateway);

        $this->asCoordinator()->post(route('api.v1.coordination.ficha-imports.store'), ['files' => [$this->workbook('Cualquiera')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('files');
    }
}
