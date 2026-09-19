<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\AiAssistance\Application\IndexAcademicSource;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Infrastructure\Jobs\ExtractAcademicSourceJob;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSourceExtraction;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AcademicSourceOcrTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        config([
            'mistral.enabled' => true,
            'mistral.base_url' => 'https://api.mistral.test/v1',
            'mistral.api_key' => 'test-key',
        ]);
        Storage::fake('private');
        Queue::fake();
    }

    public function test_coordinator_uploads_a_pdf_and_job_persists_mistral_markdown(): void
    {
        Http::fake([
            'https://api.mistral.test/v1/files' => Http::response(['id' => 'file-1'], 200),
            'https://api.mistral.test/v1/ocr' => Http::response([
                'model' => 'mistral-ocr-4-1',
                'pages' => [
                    ['index' => 0, 'markdown' => '# Marco institucional'],
                    ['index' => 1, 'markdown' => "## Resultado\n\nDiseña software seguro."],
                ],
                'usage_info' => ['pages_processed' => 2],
            ], 200, ['x-request-id' => 'request-1']),
            'https://api.mistral.test/v1/files/file-1' => Http::response([], 204),
        ]);
        $source = $this->createSource();

        $response = $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('reglamento.pdf', 20, 'application/pdf')],
        );
        $response->assertStatus(202)->assertJsonStructure(['extraction_id', 'execution_id', 'status']);

        $extraction = AcademicSourceExtraction::query()->firstOrFail();
        $this->assertDatabaseHas('objetos_almacenados', [
            'id' => $extraction->objeto_pdf_id,
            'clasificacion' => 'academic_source_pdf',
        ]);
        Queue::assertPushed(ExtractAcademicSourceJob::class, fn (ExtractAcademicSourceJob $job): bool => $job->extractionId === $extraction->id);

        (new ExtractAcademicSourceJob($extraction->id))->handle(
            app(AcademicSourceOcrGateway::class),
            app(IndexAcademicSource::class),
            app(RecordAuditEvent::class),
        );

        $source = $source->fresh();
        $this->assertSame("# Marco institucional\n\n## Resultado\n\nDiseña software seguro.", $source->contenido);
        $this->assertSame('completada', $source->estado_ingesta);
        $this->assertNotNull($source->objeto_pdf_id);
        $this->assertSame('completada', $extraction->fresh()->estado);
        $this->assertSame('completada', JobExecution::query()->firstOrFail()->estado);
        $this->assertDatabaseHas('fragmentos_fuente_academica', [
            'fuente_academica_id' => $source->id,
            'titulo_fragmento' => 'Resultado',
        ]);
        Http::assertSentCount(3);
    }

    public function test_empty_ocr_markdown_fails_without_fallback_content(): void
    {
        Http::fake([
            'https://api.mistral.test/v1/files' => Http::response(['id' => 'file-2'], 200),
            'https://api.mistral.test/v1/ocr' => Http::response([
                'model' => 'mistral-ocr-4-1',
                'pages' => [['index' => 0, 'markdown' => '']],
            ], 200),
            'https://api.mistral.test/v1/files/file-2' => Http::response([], 204),
        ]);
        $source = $this->createSource();

        $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('vacio.pdf', 20, 'application/pdf')],
        )->assertStatus(202);
        $extraction = AcademicSourceExtraction::query()->firstOrFail();

        (new ExtractAcademicSourceJob($extraction->id))->handle(
            app(AcademicSourceOcrGateway::class),
            app(IndexAcademicSource::class),
            app(RecordAuditEvent::class),
        );

        $this->assertSame('fallida', $extraction->fresh()->estado);
        $this->assertSame('fallida', $source->fresh()->estado_ingesta);
        $this->assertNull($source->fresh()->contenido);
        $this->assertSame(1, StoredObject::query()->count(), 'El PDF recibido se conserva privado aunque OCR falle.');
    }

    private function createSource(): AcademicSource
    {
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.sources.store'), ['nombre' => 'Fuente OCR'])
            ->assertCreated();

        return AcademicSource::query()->where('nombre', 'Fuente OCR')->firstOrFail();
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)
            ->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }
}
