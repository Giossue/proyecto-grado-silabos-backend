<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Application\AcademicSourceOcrExecutionFinalizer;
use App\Modules\Configuration\Application\Contracts\AcademicSourceFigureCropper;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Infrastructure\Jobs\PollAcademicSourceOcrJob;
use App\Modules\Configuration\Infrastructure\Jobs\SubmitAcademicSourceOcrJob;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
            'openparser.enabled' => true,
            'openparser.base_url' => 'https://openparser.test',
            'openparser.api_key' => 'test-token',
            'openparser.ocr_model' => 'paddleocr-vl-1.6',
            'ai.embeddings.driver' => 'disabled',
        ]);
        Storage::fake('private');
        Queue::fake();
    }

    public function test_coordinator_uploads_pdf_and_persists_structured_document(): void
    {
        $this->fakeCompletedJob([
            $this->textElement('title', 'document_title', '# Marco institucional'),
            $this->textElement('heading', 'heading', '### Resultado'),
            $this->textElement('paragraph', 'paragraph', 'Diseña software seguro.'),
        ]);
        $source = $this->createSource();

        $response = $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('reglamento.pdf', 20, 'application/pdf')],
        );
        $response->assertStatus(202)->assertJsonStructure(['execution_id', 'status']);
        $execution = JobExecution::query()->firstOrFail();
        $this->assertSame('fuente_academica.openparser', $execution->tipo);
        Queue::assertPushed(SubmitAcademicSourceOcrJob::class, fn (SubmitAcademicSourceOcrJob $job): bool => $job->executionId === $execution->id);

        $this->runJobs($execution);

        $source = $source->fresh();
        $this->assertSame('completada', $source->estado_ingesta);
        $this->assertSame('doc', $source->documento['type']);
        $this->assertSame('heading', $source->documento['content'][0]['type']);
        $this->assertSame(1, $source->documento['content'][0]['attrs']['level']);
        $this->assertSame('Marco institucional', $source->documento['content'][0]['content'][0]['text']);
        $this->assertSame(3, $source->documento['content'][1]['attrs']['level']);
        $this->assertSame('Resultado', $source->documento['content'][1]['content'][0]['text']);
        $this->assertStringContainsString('Diseña software seguro.', (string) $source->contenido);
        $this->assertSame('completada', $execution->fresh()->estado);
        $this->assertDatabaseHas('fragmentos_fuente_academica', [
            'fuente_academica_id' => $source->id,
            'titulo_fragmento' => 'Resultado',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://openparser.test/parse/async'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Idempotency-Key'));
    }

    public function test_tables_with_spans_and_images_keep_their_logical_order(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $this->fakeCompletedJob([
            $this->textElement('intro', 'paragraph', 'Texto previo'),
            [
                'id' => 'table', 'kind' => 'table', 'locations' => [],
                'row_count' => 2, 'column_count' => 3,
                'cells' => [
                    $this->tableCell('c1', 0, 0, 'Campo', 2, 1, 'column_header'),
                    $this->tableCell('c2', 0, 1, 'Valores', 1, 2, 'column_header'),
                    $this->tableCell('c3', 1, 1, 'A'),
                    $this->tableCell('c4', 1, 2, ''),
                ],
            ],
            [
                'id' => 'figure', 'kind' => 'figure', 'locations' => [],
                'asset_id' => 'asset-1', 'caption' => 'Sello institucional', 'caption_spans' => [],
            ],
        ], [[
            'id' => 'asset-1', 'kind' => 'figure', 'data_base64' => $png, 'mime_type' => 'image/png',
        ]]);
        $source = $this->createSource();
        $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('recursos.pdf', 20, 'application/pdf')],
        )->assertStatus(202);

        $this->runJobs(JobExecution::query()->firstOrFail());

        $source = $source->fresh();
        $types = array_column($source->documento['content'], 'type');
        $this->assertSame(['paragraph', 'table', 'sourceImage'], $types);
        $table = $source->documento['content'][1];
        $this->assertSame(2, $table['content'][0]['content'][0]['attrs']['rowspan']);
        $this->assertSame(2, $table['content'][0]['content'][1]['attrs']['colspan']);
        $this->assertSame(
            ['type' => 'paragraph'],
            $table['content'][1]['content'][1]['content'][0],
            'Una celda vacía debe ser un párrafo válido, nunca un nodo de texto vacío.',
        );
        $asset = StoredObject::query()->where('clasificacion', 'academic_source_image')->firstOrFail();
        $this->assertSame($asset->id, $source->documento['content'][2]['attrs']['objectId']);
        Storage::disk('private')->assertExists($asset->ruta_interna);

        $detail = $this->actingAsCoordinator()->getJson(route('api.v1.coordination.sources.show', $source));
        $detail->assertOk()->assertJsonPath('source.document.content.2.attrs.src', route('api.v1.coordination.sources.assets.show', [
            'source' => $source->id,
            'asset' => $asset->id,
        ]));
    }

    public function test_empty_text_nodes_are_removed_before_persisting_a_source_document(): void
    {
        $source = $this->createSource();

        $this->actingAsCoordinator()->putJson(
            route('api.v1.coordination.sources.content.update', $source),
            ['document' => [
                'schemaVersion' => 1,
                'type' => 'doc',
                'content' => [[
                    'type' => 'table',
                    'content' => [[
                        'type' => 'tableRow',
                        'content' => [[
                            'type' => 'tableCell',
                            'content' => [[
                                'type' => 'paragraph',
                                'content' => [['type' => 'text', 'text' => '']],
                            ]],
                        ]],
                    ]],
                ]],
            ]],
        )->assertOk();

        $paragraph = $source->fresh()->documento['content'][0]['content'][0]['content'][0]['content'][0];
        $this->assertSame(['type' => 'paragraph'], $paragraph);
    }

    public function test_database_rejects_empty_text_nodes_in_source_documents(): void
    {
        $source = $this->createSource();

        $this->expectException(QueryException::class);
        DB::table('fuentes_academicas')->where('id', $source->id)->update([
            'documento_fuente_academica' => json_encode([
                'schemaVersion' => 1,
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => '']],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_figure_without_asset_is_cropped_from_private_pdf_in_order(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        $this->app->instance(AcademicSourceFigureCropper::class, new class($png) implements AcademicSourceFigureCropper
        {
            public function __construct(private readonly string $png) {}

            public function crop(StoredObject $pdf, int $pageNumber, float $sourcePageWidth, float $sourcePageHeight, array $bbox): array
            {
                return ['bytes' => $this->png, 'mime' => 'image/png'];
            }
        });
        $this->fakeCompletedJob([
            $this->textElement('before', 'paragraph', 'Antes'),
            [
                'id' => 'figure-with-bbox', 'kind' => 'figure',
                'locations' => [[
                    'page_number' => 1,
                    'bbox' => ['left' => 10, 'top' => 20, 'right' => 80, 'bottom' => 90],
                ]],
                'caption' => 'Figura detectada', 'caption_spans' => [],
            ],
            $this->textElement('after', 'paragraph', 'Después'),
        ]);
        $source = $this->createSource();
        $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('figura.pdf', 20, 'application/pdf')],
        )->assertStatus(202);

        $this->runJobs(JobExecution::query()->firstOrFail());

        $this->assertSame(
            ['paragraph', 'sourceImage', 'paragraph'],
            array_column($source->fresh()->documento['content'], 'type'),
        );
        $this->assertDatabaseCount('objetos_almacenados', 2);
    }

    public function test_empty_result_fails_without_replacing_previous_document(): void
    {
        $this->fakeCompletedJob([]);
        $source = $this->createSource();
        $original = $source->documento;
        $this->actingAsCoordinator()->post(
            route('api.v1.coordination.sources.pdf.ingest', $source),
            ['pdf' => UploadedFile::fake()->create('vacio.pdf', 20, 'application/pdf')],
        )->assertStatus(202);

        $this->runJobs(JobExecution::query()->firstOrFail());

        $execution = JobExecution::query()->firstOrFail();
        $this->assertSame('fallida', $execution->fresh()->estado);
        $this->assertSame('fallida', $source->fresh()->estado_ingesta);
        $this->assertSame($original, $source->fresh()->documento);
        $this->assertSame(1, StoredObject::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  list<array<string, mixed>>  $assets
     */
    private function fakeCompletedJob(array $elements, array $assets = []): void
    {
        $elementIds = array_values(array_map(static fn (array $element): string => (string) $element['id'], $elements));
        $document = [
            'output_format' => 'openparser@1',
            'document_id' => 'doc-1',
            'provenance' => ['provider' => 'openparser', 'model' => 'paddleocr-vl-1.6'],
            'text' => implode("\n", array_values(array_filter(array_map(
                static fn (array $element): ?string => is_string($element['text'] ?? null) ? $element['text'] : null,
                $elements,
            )))),
            'markdown' => '',
            'pages' => [[
                'number' => 1, 'width' => 100, 'height' => 100, 'unit' => 'pixel',
                'rotation_degrees' => 0, 'languages' => [],
                'element_ids' => $elementIds, 'reading_order' => $elementIds,
            ]],
            'elements' => $elements,
            'text_annotations' => [], 'relations' => [], 'assets' => $assets,
        ];
        Http::fake([
            'https://openparser.test/parse/async' => Http::response([
                'id' => 'opj_1', 'operation' => 'parse', 'status' => 'queued',
                'output_format' => 'openparser@1',
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ], 202),
            'https://openparser.test/jobs/opj_1' => Http::response([
                'id' => 'opj_1', 'operation' => 'parse', 'status' => 'succeeded',
                'output_format' => 'openparser@1',
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'progress' => ['completed' => 1, 'total' => 1],
            ], 200),
            'https://openparser.test/jobs/opj_1/result*' => Http::response($document, 200),
        ]);
    }

    /** @return array<string, mixed> */
    private function textElement(string $id, string $role, string $text): array
    {
        return [
            'id' => $id, 'kind' => 'text', 'role' => $role, 'text' => $text,
            'locations' => [], 'spans' => [], 'languages' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function tableCell(
        string $id,
        int $row,
        int $column,
        string $text,
        int $rowspan = 1,
        int $colspan = 1,
        string $role = 'body',
    ): array {
        return [
            'id' => $id, 'row_index' => $row, 'column_index' => $column,
            'row_span' => $rowspan, 'column_span' => $colspan, 'role' => $role, 'text' => $text,
            'spans' => [], 'locations' => [], 'element_ids' => [],
        ];
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
