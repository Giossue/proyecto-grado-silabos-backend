<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\SupportDocument;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class SupportDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('private');
    }

    public function test_coordinator_uploads_a_pdf_that_teachers_of_the_career_view(): void
    {
        $this->asRole('coordinador@silabos.test');
        $response = $this->post(route('api.v1.coordination.support-documents.store'), [
            'title' => '  Proyecto   de carrera ',
            'file' => UploadedFile::fake()->createWithContent('proyecto.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('document.title', 'Proyecto de carrera')
            ->assertJsonPath('document.view', 'lista')
            ->assertJsonMissingPath('document.uploaded_by')
            ->assertJsonMissingPath('document.file_name');
        $id = $response->json('document.id');
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.documento_apoyo.subida', 'recurso_id' => $id]);

        $this->asRole('docente@silabos.test');
        $this->getJson(route('api.v1.teacher.support-documents.index'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $view = $this->get(route('api.v1.support-documents.view', $id))->assertOk();
        $this->assertSame('application/pdf', $view->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $view->headers->get('Content-Disposition'));
        $this->assertStringContainsString('filename=proyecto-de-carrera.pdf', (string) $view->headers->get('Content-Disposition'));
    }

    public function test_word_documents_are_converted_to_pdf_for_the_view(): void
    {
        config(['services.gotenberg.url' => 'http://gotenberg:3000/']);
        $sent = null;
        Http::fake(function (ClientRequest $request) use (&$sent) {
            foreach ($request->data() as $part) {
                if (($part['name'] ?? null) === 'files') {
                    $sent = $part['filename'] ?? null;
                }
            }

            return Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf']);
        });

        $this->asRole('coordinador@silabos.test');
        $id = $this->post(route('api.v1.coordination.support-documents.store'), [
            'title' => 'Habilidades blandas',
            'file' => UploadedFile::fake()->createWithContent('habilidades.docx', $this->docx()),
        ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

        $this->assertSame('documento.docx', $sent);
        $document = SupportDocument::query()->findOrFail($id);
        $this->assertSame('lista', $document->estado_vista);
        $this->assertSame('documento_apoyo_vista', $document->preview?->clasificacion);
        $this->assertSame(self::PDF, Storage::disk('private')->get((string) $document->preview?->ruta_interna));
        $this->get(route('api.v1.support-documents.view', $id))->assertOk();
    }

    public function test_every_excel_sheet_is_fitted_to_the_page_width_before_converting(): void
    {
        config(['services.gotenberg.url' => 'http://gotenberg:3000/']);
        $sent = null;
        Http::fake(function (ClientRequest $request) use (&$sent) {
            foreach ($request->data() as $part) {
                if (($part['name'] ?? null) === 'files') {
                    $sent = $part['contents'];
                }
            }

            return Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf']);
        });

        $this->asRole('coordinador@silabos.test');
        $this->post(route('api.v1.coordination.support-documents.store'), [
            'title' => 'TIC TAC',
            'file' => UploadedFile::fake()->createWithContent('tic.xlsx', $this->workbook()),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = tempnam(sys_get_temp_dir(), 'apoyo-test-').'.xlsx';
        file_put_contents($path, (string) $sent);
        $book = IOFactory::load($path);
        unlink($path);
        $this->assertSame(2, $book->getSheetCount());
        foreach ($book->getWorksheetIterator() as $sheet) {
            $this->assertTrue($sheet->getPageSetup()->getFitToPage());
            $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
            $this->assertSame(0, $sheet->getPageSetup()->getFitToHeight());
        }
        $this->assertSame('landscape', $book->getSheet(1)->getPageSetup()->getOrientation());
    }

    public function test_without_the_converter_the_document_has_no_view(): void
    {
        config(['services.gotenberg.url' => '']);
        $this->asRole('coordinador@silabos.test');
        $id = $this->post(route('api.v1.coordination.support-documents.store'), [
            'title' => 'Malla en Excel',
            'file' => UploadedFile::fake()->createWithContent('malla.xlsx', $this->workbook()),
        ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

        $this->assertSame('sin_vista', SupportDocument::query()->findOrFail($id)->estado_vista);
        $this->get(route('api.v1.support-documents.view', $id))->assertNotFound();
    }

    public function test_a_request_above_the_server_limit_answers_in_spanish(): void
    {
        $this->asRole('coordinador@silabos.test');
        $limit = ini_get('post_max_size');
        $this->call('POST', route('api.v1.coordination.support-documents.store'), [], [], [], [
            'CONTENT_LENGTH' => (string) (((int) $limit + 1) * 1024 * 1024 * 1024),
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413)->assertExactJson(['message' => 'El archivo es demasiado grande.']);
    }

    public function test_upload_rejects_other_formats_and_requires_a_title(): void
    {
        $this->asRole('coordinador@silabos.test');
        $this->post(route('api.v1.coordination.support-documents.store'), [
            'title' => '',
            'file' => UploadedFile::fake()->createWithContent('notas.txt', 'texto'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'file']);
        $this->assertSame(0, SupportDocument::query()->count());
    }

    public function test_only_the_career_coordinator_manages_and_other_careers_do_not_see_the_document(): void
    {
        $this->asRole('docente@silabos.test');
        $this->post(route('api.v1.coordination.support-documents.store'), [
            'title' => 'Intento',
            'file' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->asRole('bibliotecario@silabos.test');
        $this->getJson(route('api.v1.teacher.support-documents.index'))->assertForbidden();

        $other = $this->foreignDocument();
        $this->asRole('docente@silabos.test');
        $this->getJson(route('api.v1.teacher.support-documents.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->get(route('api.v1.support-documents.view', $other))->assertNotFound();
        $this->asRole('coordinador@silabos.test');
        $this->deleteJson(route('api.v1.coordination.support-documents.destroy', $other))->assertNotFound();
    }

    public function test_deleting_a_document_removes_its_files_from_the_server(): void
    {
        config(['services.gotenberg.url' => 'http://gotenberg:3000/']);
        Http::fake(fn () => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf']));
        $this->asRole('coordinador@silabos.test');
        $id = $this->post(route('api.v1.coordination.support-documents.store'), [
            'title' => 'Para borrar',
            'file' => UploadedFile::fake()->createWithContent('b.docx', $this->docx()),
        ], ['Accept' => 'application/json'])->json('document.id');
        $document = SupportDocument::query()->with(['original', 'preview'])->findOrFail($id);
        $paths = [$document->original->ruta_interna, (string) $document->preview?->ruta_interna];
        $objects = [$document->archivo_id, (string) $document->archivo_vista_id];
        Storage::disk('private')->assertExists($paths);

        $this->deleteJson(route('api.v1.coordination.support-documents.destroy', $id))->assertOk();

        $this->assertDatabaseMissing('documentos_apoyo', ['documento_apoyo_id' => $id]);
        $this->assertSame(0, DB::table('archivos')->whereIn('archivo_id', $objects)->count());
        Storage::disk('private')->assertMissing($paths);
        Storage::disk('private')->assertDirectoryEmpty(dirname($paths[0]));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.documento_apoyo.eliminacion', 'recurso_id' => $id]);
        $this->asRole('docente@silabos.test');
        $this->getJson(route('api.v1.teacher.support-documents.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_files_stay_immutable(): void
    {
        $object = StoredObject::query()->create([
            'disco' => 'private', 'ruta_interna' => 'exports/x/y/syllabus.pdf', 'nombre_logico' => 'silabo.pdf', 'mime' => 'application/pdf',
            'tamano_bytes' => 10, 'huella_sha256' => str_repeat('a', 64), 'clasificacion' => 'syllabus_export', 'propietario_usuario_id' => null,
        ]);

        $this->expectException(QueryException::class);
        DB::table('archivos')->where('archivo_id', $object->id)->delete();
    }

    private function foreignDocument(): string
    {
        $career = Career::query()->firstOrFail();
        $other = Career::query()->create([
            'facultad_id' => $career->facultad_id,
            'codigo_carrera' => 'OTRA-'.Str::upper(Str::random(4)),
            'nombre' => 'Otra carrera',
            'activo' => true,
            'cantidad_ciclos_malla' => 8,
        ]);
        $uploader = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $id = (string) Str::uuid();
        $path = "documentos-apoyo/{$other->id}/{$id}/original.pdf";
        Storage::disk('private')->put($path, self::PDF);
        $object = StoredObject::query()->create([
            'disco' => 'private',
            'ruta_interna' => $path,
            'nombre_logico' => 'ajeno.pdf',
            'mime' => 'application/pdf',
            'tamano_bytes' => strlen(self::PDF),
            'huella_sha256' => hash('sha256', self::PDF),
            'clasificacion' => 'documento_apoyo',
            'propietario_usuario_id' => $uploader->id,
        ]);

        return SupportDocument::query()->create([
            'documento_apoyo_id' => $id,
            'carrera_id' => $other->id,
            'titulo_documento' => 'Ajeno',
            'archivo_id' => $object->id,
            'archivo_vista_id' => $object->id,
            'estado_vista' => 'lista',
            'subido_por' => $uploader->id,
            'subido_en' => now(),
        ])->documento_apoyo_id;
    }

    private function workbook(): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Resumen')->setCellValue('A1', 'Competencia');
        $wide = $book->createSheet()->setTitle('Detalle');
        foreach (range('A', 'L') as $column) {
            $wide->setCellValue("{$column}1", "Columna {$column}");
        }
        $path = tempnam(sys_get_temp_dir(), 'apoyo-libro-').'.xlsx';
        (new Xlsx($book))->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function docx(): string
    {
        $word = new PhpWord;
        $word->addSection()->addText('Habilidades blandas');
        $path = tempnam(sys_get_temp_dir(), 'apoyo-word-').'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function asRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $role = RoleAssignment::query()->where('usuario_id', $user->id)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $role->id]);
    }
}
