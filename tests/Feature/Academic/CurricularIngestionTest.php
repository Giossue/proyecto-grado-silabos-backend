<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Application\Actions\ReleaseCurricularImportDocuments;
use App\Modules\Academic\Application\CurricularSourceBlocks;
use App\Modules\Academic\Application\CurricularWorkbookReader;
use App\Modules\Academic\Application\PrepareCurricularImport;
use App\Modules\Academic\Domain\Contracts\CurricularExtractionGateway;
use App\Modules\Academic\Infrastructure\Gateways\HostedCurricularExtractionGateway;
use App\Modules\Academic\Infrastructure\Jobs\PrepareCurricularImportJob;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\UsesProjectData;
use Tests\TestCase;
use ZipArchive;

class CurricularIngestionTest extends TestCase
{
    use RefreshDatabase;
    use UsesProjectData;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        Http::preventStrayRequests();
        $this->seed(DatabaseSeeder::class);
        $this->subject = Subject::query()->firstOrFail();
        $this->useProjectData('habilidades_blandas', 'Habilidades blandas');
        $this->asRole('coordinador@silabos.test');
        $this->mock(CurricularExtractionGateway::class, function (MockInterface $mock): void {
            $mock->shouldReceive('available')->andReturn(true);
            $mock->shouldReceive('extract')->andReturnUsing(function (array $input): array {
                $this->assertSame($this->subject->career->nombre, $input['career']);
                $this->assertSame(['habilidades_blandas'], array_column($input['known_concepts'], 'key'));
                $this->assertNotEmpty($input['known_concepts'][0]['sections']);
                $block = collect($input['blocks'])->first(fn ($block) => str_contains($block['text'], 'Pensamiento crítico'));

                return $this->rawProposal($block, ['Pensamiento crítico']);
            });
        });
    }

    public function test_xlsx_upload_prepares_verifiable_private_proposal_and_only_confirmation_changes_curriculum(): void
    {
        config(['ai.embeddings.driver' => 'openai']);
        $file = $this->workbook();
        $response = $this->postJson($this->storeUrl(), ['files' => [$file], 'instruction' => 'Leer las habilidades de la materia.'])
            ->assertAccepted()->assertJsonPath('status', 'pendiente')->assertJsonPath('proposal', null);
        $import = CurricularImport::query()->findOrFail($response->json('id'));
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->assertDatabaseCount('conceptos_curriculares', 1);
        $this->assertDatabaseCount('documentos_ia', 0);
        Queue::assertPushed(PrepareCurricularImportJob::class, fn ($job) => $job->importId === $import->id);
        $object = StoredObject::query()->where('clasificacion', 'curricular_workbook')->firstOrFail();
        $this->assertSame('private', $object->disco);
        $this->assertSame($this->subject->carrera_id, $object->carrera_id);
        Storage::disk('private')->assertExists($object->ruta_interna);
        $this->assertStringNotContainsString($object->ruta_interna, $response->getContent());
        $this->assertSame('recursos.xlsx', $response->json('documents.0.original_name'));
        $download = $response->json('documents.0.original_url');
        $this->get($download)->assertOk()->assertDownload('recursos.xlsx');
        $source = AcademicSource::query()->findOrFail($import->documentos_importacion[0]['source_id']);
        $this->assertStringContainsString('A2:', $source->contenido);
        $this->assertStringContainsString('Hoja «Recursos»', $source->contenido);
        $this->assertTrue(app(PrepareCurricularImport::class)->step($import->id));
        $detail = $this->getJson($this->showUrl($import))->assertOk()->assertJsonPath('status', 'lista');
        $this->assertNull($detail->json('proposal.values.0.issue_code'));
        $this->assertSame($this->subject->id, $detail->json('proposal.values.0.subject_id'));
        $this->assertSame('Recursos!A2:C2', $detail->json('proposal.values.0.evidence.0.locator'));
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->assertDatabaseCount('conceptos_curriculares', 1);
        $this->postJson($this->showUrl($import).'/apply', [
            'confirmed' => true, 'expected_fingerprint' => $detail->json('fingerprint'),
            'items' => [['id' => $detail->json('proposal.values.0.id')]],
        ])->assertOk()->assertJsonPath('status', 'aplicada');
        $value = CurricularValue::query()->firstOrFail();
        $this->assertSame(['Pensamiento crítico'], $value->valor_curricular);
        $this->assertSame($source->id, $value->evidencia_curricular[0]['source_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $value->evidencia_curricular[0]['source_hash']);
        $this->assertTrue(app(PrepareCurricularImport::class)->step($import->id));
        $this->assertDatabaseCount('valores_curriculares', 1);
        foreach (['admin@silabos.test', 'docente@silabos.test'] as $email) {
            $this->asRole($email);
            $this->get($download)->assertForbidden();
        }
    }

    public function test_same_upload_and_source_content_are_idempotent_without_duplicating_files_jobs_or_sources(): void
    {
        $file = $this->workbook();
        $first = $this->postJson($this->storeUrl(), ['files' => [$file]])->assertAccepted()->json('id');
        $second = $this->postJson($this->storeUrl(), ['files' => [$file]])->assertAccepted()->json('id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('importaciones_curriculares', 1);
        $this->assertDatabaseCount('fuentes_academicas', 1);
        $this->assertSame(1, JobExecution::query()->where('tipo', 'curriculo.extraer')->count());
        $this->assertSame(1, StoredObject::query()->where('clasificacion', 'curricular_workbook')->count());
        Queue::assertPushed(PrepareCurricularImportJob::class, 1);
        $this->travel(61)->seconds();
        $import = CurricularImport::query()->findOrFail($first);
        $path = StoredObject::query()->where('clasificacion', 'curricular_workbook')->firstOrFail()->ruta_interna;
        $import->update(['estado_importacion' => 'aplicada']);
        app(ReleaseCurricularImportDocuments::class)->execute($import->fresh());
        $this->assertDatabaseCount('fuentes_academicas', 0);
        Storage::disk('private')->assertMissing($path);
        $third = $this->postJson($this->storeUrl(), ['files' => [$file]])->assertAccepted()->json('id');
        $this->assertNotSame($first, $third);
        $this->assertDatabaseCount('fuentes_academicas', 1);
        Storage::disk('private')->assertExists($path);
        $this->postJson($this->storeUrl(), ['source_ids' => [AcademicSource::query()->firstOrFail()->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_upload_and_existing_sources_enforce_coordinator_role_and_career_scope(): void
    {
        foreach (['admin@silabos.test', 'docente@silabos.test'] as $email) {
            $this->asRole($email);
            $this->postJson($this->storeUrl(), ['files' => [$this->workbook()]])->assertForbidden();
        }
        $this->asRole('coordinador@silabos.test');
        $source = $this->source('Pensamiento crítico');
        $this->postJson($this->storeUrl(), ['source_ids' => [$source->id]])->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertDatabaseCount('importaciones_curriculares', 0);
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_source_change_after_request_is_rejected_before_extraction_can_change_curriculum(): void
    {
        [$id, $source] = $this->uploaded();
        $source->update(['documento' => ['type' => 'doc', 'content' => []]]);
        try {
            app(PrepareCurricularImport::class)->step($id);
            $this->fail('La modificación de la fuente debía invalidar la lectura pendiente.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fuente cambió', $exception->getMessage());
        }
        $this->assertDatabaseCount('conceptos_curriculares', 1);
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_invented_values_and_partial_numeric_matches_are_kept_as_unconfirmable_exceptions(): void
    {
        $source = $this->source('La materia tiene 100 horas y desarrolla Pensamiento crítico.');
        $block = app(CurricularSourceBlocks::class)->forSource($source)[0];
        foreach ([['Inventado'], ['10'], ['---']] as $values) {
            $proposal = app(PrepareCurricularImport::class)->normalize($this->rawProposal($block, $values), [$block], [$this->subject]);
            $this->assertSame('invalid_evidence', $proposal['values'][0]['issue_code']);
        }
        $raw = $this->rawProposal($block, ['Pensamiento crítico']);
        $raw['values'][0]['evidence'][0]['block_id'] = 'no-existe';
        $proposal = app(PrepareCurricularImport::class)->normalize($raw, [$block], [$this->subject]);
        $this->assertSame('invalid_evidence', $proposal['values'][0]['issue_code']);
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_failed_attempt_retries_once_with_new_identity_and_old_jobs_cannot_fail_the_new_attempt(): void
    {
        [$id, $source] = $this->uploaded();
        app(PrepareCurricularImport::class)->fail($id);
        $previous = CurricularImport::query()->findOrFail($id);
        $this->assertSame('fallida', $previous->estado_importacion);
        $retryUrl = $this->showUrl($previous).'/retry';
        $retryId = $this->postJson($retryUrl)->assertAccepted()->assertJsonPath('status', 'pendiente')->json('id');
        $this->assertNotSame($id, $retryId);
        $this->postJson($retryUrl)->assertAccepted()->assertJsonPath('id', $retryId);
        $this->assertDatabaseCount('importaciones_curriculares', 2);
        $this->assertTrue(app(PrepareCurricularImport::class)->step($retryId));
        app(PrepareCurricularImport::class)->fail($id);
        $this->assertSame('lista', CurricularImport::query()->findOrFail($retryId)->estado_importacion);
        $this->assertSame('fallida', $previous->fresh()->estado_importacion);
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_only_data_used_by_the_template_is_imported_and_the_rest_is_reported(): void
    {
        CurricularConcept::query()->create(['clave_concepto' => 'convenios', 'etiqueta_concepto' => 'Convenios',
            'tipo_concepto' => 'lista', 'columnas_concepto' => null]);
        $source = $this->source('Pensamiento crítico. Convenio con la empresa Demo.');
        $block = app(CurricularSourceBlocks::class)->forSource($source)[0];
        $raw = $this->rawProposal($block, ['Pensamiento crítico']);
        $raw['definitions'][] = ['key' => 'convenios', 'label' => 'Convenios', 'type' => 'lista', 'columns' => []];
        $raw['values'][] = ['concept_key' => 'convenios', 'subject_code' => null, 'subject_name' => null, 'value' => ['Convenio con la empresa Demo.'],
            'evidence' => [['block_id' => $block['id'], 'quote' => 'Convenio con la empresa Demo.']], 'issue' => null];
        $proposal = app(PrepareCurricularImport::class)->normalize($raw, [$block], [$this->subject]);
        $this->assertSame(['habilidades_blandas'], array_column($proposal['values'], 'concept_key'));
        $this->assertSame(['habilidades_blandas'], array_column($proposal['definitions'], 'key'));
        $this->assertContains('El documento también trae «Convenios», que la plantilla no pide; no se importó.', $proposal['issues']);
    }

    public function test_import_requires_project_data_used_by_the_template(): void
    {
        $template = SyllabusTemplate::institutional();
        foreach ($template->sections() as $section) {
            $template->updateSectionDocument($section->id, ['bloques' => $section->blocks()
                ->reject(fn ($block): bool => $block->clave === 'proyecto_habilidades_blandas')->map->toDocument()->values()->all()]);
        }
        $this->assertTrue(CurricularConcept::query()->where('clave_concepto', 'habilidades_blandas')->exists());
        $this->post(route('api.v1.coordination.curricular-imports.store'), [
            'files' => [UploadedFile::fake()->createWithContent('Bibliografia.xlsx', 'x')],
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.files.0', 'La plantilla del sílabo todavía no tiene datos del Proyecto de carrera. Administración debe agregarlos primero.');
        $this->assertDatabaseCount('importaciones_curriculares', 0);
    }

    public function test_partial_lists_from_one_source_merge_but_different_sources_remain_explicit_conflicts(): void
    {
        $source = $this->source('Pensamiento crítico y Comunicación.');
        $block = app(CurricularSourceBlocks::class)->forSource($source)[0];
        $raw = $this->rawProposal($block, ['Pensamiento crítico']);
        $raw['values'][] = $this->rawProposal($block, ['Comunicación'])['values'][0];
        $proposal = app(PrepareCurricularImport::class)->normalize($raw, [$block], [$this->subject]);
        $this->assertCount(1, $proposal['values']);
        $this->assertSame(['Pensamiento crítico', 'Comunicación'], $proposal['values'][0]['value']);
        $this->assertNull($proposal['values'][0]['issue_code']);
        $other = $this->source('Colaboración.');
        $otherBlock = app(CurricularSourceBlocks::class)->forSource($other)[0];
        $raw['values'][] = $this->rawProposal($otherBlock, ['Colaboración'])['values'][0];
        $proposal = app(PrepareCurricularImport::class)->normalize($raw, [$block, $otherBlock], [$this->subject]);
        $this->assertCount(2, $proposal['values']);
        $this->assertSame(['source_conflict', 'source_conflict'], array_column($proposal['values'], 'issue_code'));
    }

    public function test_workbook_preserves_merged_ranges_and_cached_formula_markers_and_rejects_macros_and_entities(): void
    {
        $file = $this->workbook();
        $document = app(CurricularWorkbookReader::class)->read($file->getRealPath());
        $text = json_encode($document, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('A1:C1', $text);
        $this->assertStringContainsString('resultado guardado de fórmula', $text);
        foreach (['macro', 'entity'] as $kind) {
            $file = $this->workbook($kind);
            try {
                app(CurricularWorkbookReader::class)->read($file->getRealPath());
                $this->fail('El libro con contenido no permitido debía rechazarse.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('files', $exception->errors());
            }
        }
        $source = $this->source('Tabla de prueba');
        $cell = fn (string $text, array $attrs = []): array => ['type' => 'tableCell', 'attrs' => $attrs,
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
        $source->update(['documento' => ['type' => 'doc', 'content' => [[
            'type' => 'table', 'attrs' => ['sourcePage' => 7], 'content' => [
                ['type' => 'tableRow', 'content' => [$cell('Unidad profesional', ['rowspan' => 2, 'colspan' => 2]), $cell('Primera materia')]],
                ['type' => 'tableRow', 'content' => [$cell('Segunda materia')]],
            ],
        ]]]]);
        $blocks = app(CurricularSourceBlocks::class)->forSource($source);
        $this->assertStringContainsString('Columna 1 (celda combinada de fila anterior): Unidad profesional', $blocks[1]['text']);
        $this->assertStringContainsString('Columna 3: Segunda materia', $blocks[1]['text']);
        $this->assertSame('Página 7, bloque 1, fila 2', $blocks[1]['locator']);
        Http::assertNothingSent();
    }

    public function test_hosted_gateway_requires_complete_json_response_and_sends_data_as_user_content(): void
    {
        config(['curricular_import.enabled' => true, 'curricular_import.api_key' => 'synthetic-test-key',
            'curricular_import.model' => 'synthetic-model', 'curricular_import.base_url' => 'https://provider.test']);
        $proposal = ['definitions' => [], 'values' => [], 'issues' => ['Dato ausente']];
        Http::fake(['https://provider.test/chat/completions' => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'length', 'message' => ['content' => json_encode($proposal)]]]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($proposal)]]]])]);
        $gateway = new HostedCurricularExtractionGateway;
        $input = ['career' => 'Software', 'blocks' => [['text' => 'Texto no confiable de prueba']]];
        try {
            $gateway->extract($input);
            $this->fail('Una respuesta truncada debía rechazarse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incompleta', $exception->getMessage());
        }
        $this->assertSame($proposal, $gateway->extract($input));
        Http::assertSent(fn ($request) => $request['messages'][0]['role'] === 'system'
            && $request['messages'][1]['role'] === 'user'
            && json_decode($request['messages'][1]['content'], true) === $input);
        config(['curricular_import.base_url' => 'http://provider.test']);
        $this->assertFalse($gateway->available());
    }

    public function test_changed_workbook_with_same_filename_creates_new_source_without_overwriting_previous_evidence(): void
    {
        $first = $this->postJson($this->storeUrl(), ['files' => [$this->workbook()]])->assertAccepted()->json('id');
        $source = AcademicSource::query()->firstOrFail();
        $previousDocument = $source->documento;
        $second = $this->postJson($this->storeUrl(), ['files' => [$this->workbook(content: 'Comunicación')]])->assertAccepted()->json('id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('fuentes_academicas', 2);
        $this->assertDatabaseCount('importaciones_curriculares', 2);
        $this->assertSame($previousDocument, $source->fresh()->documento);
        $this->assertSame('recursos (2)', AcademicSource::query()->whereKeyNot($source->id)->firstOrFail()->nombre);
    }

    public function test_source_ocr_or_pdf_changes_during_extraction_invalidate_the_final_proposal_commit(): void
    {
        [$id, $source] = $this->uploaded();
        $import = CurricularImport::query()->findOrFail($id);
        $original = StoredObject::query()->create([
            'disco' => 'private', 'ruta_interna' => 'pruebas/ingesta-original.pdf', 'nombre_logico' => 'original.pdf', 'mime' => 'application/pdf',
            'tamano_bytes' => 10, 'huella_sha256' => str_repeat('a', 64), 'clasificacion' => 'academic_source_pdf',
            'estado' => 'activo', 'propietario_usuario_id' => $import->solicitado_por, 'carrera_id' => $this->subject->carrera_id, 'almacenado_en' => now(),
        ]);
        $replacement = $original->replicate();
        $replacement->ruta_interna = 'pruebas/ingesta-sustitucion.pdf';
        $replacement->huella_sha256 = str_repeat('b', 64);
        $replacement->save();
        $documents = $import->documentos_importacion;
        $documents[0]['kind'] = 'pdf';
        $documents[0]['original_object_id'] = $original->id;
        $import->update(['documentos_importacion' => $documents]);
        foreach (['pendiente', 'fallida', 'pdf_replaced'] as $change) {
            $source->update(['estado_ingesta' => 'completada', 'objeto_pdf_id' => $original->id]);
            $this->mock(CurricularExtractionGateway::class, function (MockInterface $mock) use ($source, $replacement, $change): void {
                $mock->shouldReceive('extract')->once()->andReturnUsing(function (array $input) use ($source, $replacement, $change): array {
                    $source->update($change === 'pdf_replaced' ? ['objeto_pdf_id' => $replacement->id] : ['estado_ingesta' => $change]);

                    return $this->rawProposal($input['blocks'][0], ['Pensamiento crítico']);
                });
            });
            try {
                app(PrepareCurricularImport::class)->step($id);
                $this->fail('La fuente cambió durante la extracción y no debía confirmarse la propuesta.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('fuente cambió', $exception->getMessage());
            }
            $this->assertNull($import->fresh()->propuesta_importacion);
            $this->assertNotSame('lista', $import->fresh()->estado_importacion);
        }
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_combined_upload_size_is_rejected_before_requesting_extraction(): void
    {
        $this->postJson($this->storeUrl(), ['files' => [
            UploadedFile::fake()->create('primero.pdf', 16000, 'application/pdf'),
            UploadedFile::fake()->create('segundo.pdf', 16000, 'application/pdf'),
        ]])->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertDatabaseCount('importaciones_curriculares', 0);
        $this->assertDatabaseCount('fuentes_academicas', 0);
        Queue::assertNotPushed(PrepareCurricularImportJob::class);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private function rawProposal(array $block, array $values): array
    {
        return [
            'definitions' => [['key' => 'habilidades_blandas', 'label' => 'Habilidades blandas', 'type' => 'lista', 'columns' => []]],
            'values' => [[
                'concept_key' => 'habilidades_blandas', 'subject_code' => $this->subject->codigo_asignatura,
                'subject_name' => $this->subject->nombre, 'value' => $values,
                'evidence' => [['block_id' => $block['id'], 'quote' => $block['text']]], 'issue' => null,
            ]],
            'issues' => [],
        ];
    }

    private function source(string $text): AcademicSource
    {
        return AcademicSource::query()->create([
            'carrera_id' => $this->subject->carrera_id, 'nombre' => 'Fuente sintética '.(AcademicSource::query()->count() + 1), 'activo' => true,
            'estado_ingesta' => 'completada', 'contenido' => $text,
            'documento' => ['schemaVersion' => 1, 'type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['sourcePage' => 3], 'content' => [['type' => 'text', 'text' => $text]]]]],
        ]);
    }

    private function asRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
    }

    /** @return array{0: string, 1: AcademicSource} Importación recién subida y su documento temporal. */
    private function uploaded(): array
    {
        $id = $this->postJson($this->storeUrl(), ['files' => [$this->workbook()]])->assertAccepted()->json('id');
        $sourceId = CurricularImport::query()->findOrFail($id)->documentos_importacion[0]['source_id'];

        return [$id, AcademicSource::query()->findOrFail($sourceId)];
    }

    private function storeUrl(): string
    {
        return route('api.v1.coordination.curricular-imports.store');
    }

    private function showUrl(CurricularImport $import): string
    {
        return route('api.v1.coordination.curricular-imports.show', $import);
    }

    private function workbook(?string $invalid = null, string $content = 'Pensamiento crítico'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'curricular-workbook-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?>'.($invalid === 'entity' ? '<!DOCTYPE workbook [<!ENTITY xxe SYSTEM "file:///not-a-real-secret">]>' : '').'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Recursos" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_XML1, 'UTF-8');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Habilidades por asignatura</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>'.$escape($this->subject->codigo_asignatura).'</t></is></c><c r="B2" t="inlineStr"><is><t>'.$escape($this->subject->nombre).'</t></is></c><c r="C2" t="inlineStr"><is><t>'.$escape($content).'</t></is></c></row><row r="3"><c r="A3"><f>1+1</f><v>2</v></c></row></sheetData><mergeCells><mergeCell ref="A1:C1"/></mergeCells></worksheet>');
        if ($invalid === 'macro') {
            $zip->addFromString('xl/vbaProject.bin', 'macro sintética no ejecutable');
        }
        $zip->close();
        try {
            return UploadedFile::fake()->createWithContent('recursos.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
