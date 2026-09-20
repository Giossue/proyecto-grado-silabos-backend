<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Application\AcademicSourceOcrExecutionFinalizer;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Infrastructure\Jobs\PollAcademicSourceOcrJob;
use App\Modules\Configuration\Infrastructure\Jobs\SubmitAcademicSourceOcrJob;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
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
            'paddleocr.enabled' => true,
            'paddleocr.base_url' => 'https://paddleocr.test',
            'paddleocr.access_token' => 'test-token',
            'paddleocr.model' => 'PaddleOCR-VL-1.6',
            'ai.embeddings.driver' => 'disabled',
        ]);
        Storage::fake('private');
        Queue::fake();
    }

    public function test_coordinator_uploads_pdf_and_persists_structured_document(): void
    {
        $this->fakeCompletedJob("# Marco institucional\n\n## Resultado\n\nDiseña software seguro.");
        $source = $this->createSource();

        $response = $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('reglamento.pdf', 20, 'application/pdf')],
        );
        $response->assertStatus(202)->assertJsonStructure(['execution_id', 'status']);
        $execution = JobExecution::query()->firstOrFail();
        Queue::assertPushed(SubmitAcademicSourceOcrJob::class, fn (SubmitAcademicSourceOcrJob $job): bool => $job->executionId === $execution->id);

        $this->runJobs($execution);

        $source = $source->fresh();
        $this->assertSame('completada', $source->estado_ingesta);
        $this->assertSame('doc', $source->documento['type']);
        $this->assertSame('heading', $source->documento['content'][0]['type']);
        $this->assertStringContainsString('Diseña software seguro.', (string) $source->contenido);
        $this->assertSame('completada', $execution->fresh()->estado);
        $this->assertDatabaseHas('fragmentos_fuente_academica', [
            'fuente_academica_id' => $source->id,
            'titulo_fragmento' => 'Resultado',
        ]);
    }

    public function test_tables_with_spans_and_images_keep_their_logical_order(): void
    {
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $markdown = <<<'MARKDOWN'
Texto previo

<table><tr><th rowspan="2">Campo</th><th colspan="2">Valores</th></tr><tr><td>A</td><td>B</td></tr></table>

![Sello institucional](img-1.png)
MARKDOWN;
        $this->fakeCompletedJob($markdown, ['img-1.png' => $image]);
        $source = $this->createSource();
        $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('recursos.pdf', 20, 'application/pdf')],
        )->assertStatus(202);

        $execution = JobExecution::query()->firstOrFail();
        $this->runJobs($execution);

        $source = $source->fresh();
        $types = array_column($source->documento['content'], 'type');
        $this->assertSame(['paragraph', 'table', 'sourceImage'], $types);
        $table = $source->documento['content'][1];
        $this->assertSame(2, $table['content'][0]['content'][0]['attrs']['rowspan']);
        $this->assertSame(2, $table['content'][0]['content'][1]['attrs']['colspan']);
        $asset = StoredObject::query()->where('clasificacion', 'academic_source_image')->firstOrFail();
        $this->assertSame($asset->id, $source->documento['content'][2]['attrs']['objectId']);
        Storage::disk('private')->assertExists($asset->ruta_interna);

        $detail = $this->actingAsCoordinator()->getJson(route('api.v1.coordination.sources.show', $source));
        $detail->assertOk()->assertJsonPath('source.document.content.2.attrs.src', route('api.v1.coordination.sources.assets.show', [
            'source' => $source->id,
            'asset' => $asset->id,
        ]));
    }

    public function test_empty_result_fails_without_replacing_previous_document(): void
    {
        $this->fakeCompletedJob('');
        $source = $this->createSource();
        $original = $source->documento;
        $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('vacio.pdf', 20, 'application/pdf')],
        )->assertStatus(202);

        $execution = JobExecution::query()->firstOrFail();
        $this->runJobs($execution);

        $this->assertSame('fallida', $execution->fresh()->estado);
        $this->assertSame('fallida', $source->fresh()->estado_ingesta);
        $this->assertSame($original, $source->fresh()->documento);
        $this->assertSame(1, StoredObject::query()->count());
    }

    /** @param array<string, string> $images */
    private function fakeCompletedJob(string $markdown, array $images = []): void
    {
        $jsonl = json_encode([
            'result' => [
                'dataInfo' => ['numPages' => 1],
                'layoutParsingResults' => [[
                    'markdown' => ['text' => $markdown, 'images' => $images],
                    'prunedResult' => ['parsing_res_list' => []],
                    'outputImages' => [],
                ]],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        Http::fake([
            'https://paddleocr.test/api/v2/ocr/jobs' => Http::response(['code' => 0, 'data' => ['jobId' => 'job-1']], 200),
            'https://paddleocr.test/api/v2/ocr/jobs/job-1' => Http::response([
                'code' => 0,
                'data' => ['state' => 'done', 'resultUrl' => ['jsonUrl' => 'https://results.test/job-1.jsonl']],
            ], 200),
            'https://results.test/job-1.jsonl' => Http::response($jsonl, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);
    }

    private function runJobs(JobExecution $execution): void
    {
        (new SubmitAcademicSourceOcrJob($execution->id))->handle(
            app(AcademicSourceOcrGateway::class),
            app(AcademicSourceOcrExecutionFinalizer::class),
        );
        (new PollAcademicSourceOcrJob($execution->id))->handle(
            app(AcademicSourceOcrGateway::class),
            app(AcademicSourceOcrExecutionFinalizer::class),
        );
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
