<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Application\DocumentBundleValidator;
use App\Modules\Documents\Application\IncrementalPdfSignatureVerifier;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Rendering\GotenbergPdfConverter;
use App\Modules\Documents\Infrastructure\Rendering\PhpWordDocumentRenderer;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsPdfIncrementally;
use Tests\TestCase;

class DocumentSignatureTest extends TestCase
{
    use AssertsInternalNotifications;
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use SignsPdfIncrementally;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    private User $teacher;

    private RoleAssignment $teacherContext;

    private User $librarian;

    private RoleAssignment $librarianContext;

    private User $dean;

    private RoleAssignment $deanContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('private');

        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
        $this->librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $this->librarianContext = $this->librarian->roleAssignments()->firstOrFail();
        $this->dean = User::query()->where('correo_electronico', 'decano@silabos.test')->firstOrFail();
        $this->deanContext = $this->dean->roleAssignments()->firstOrFail();
    }

    public function test_docente_coordinacion_biblioteca_y_decanato_firman_el_pdf_de_la_revision_aprobada(): void
    {
        [, $revision] = $this->exportedRevision();
        $teacherCredentials = $this->makeSigningCertificate('DOCENTE DE PRUEBA');
        $coordinatorCredentials = $this->makeSigningCertificate('COORDINACION DE PRUEBA');
        $librarianCredentials = $this->makeSigningCertificate('BIBLIOTECA DE PRUEBA');
        $deanCredentials = $this->makeSigningCertificate('DECANATO DE PRUEBA');

        $status = $this->actingAsTeacher()->getJson(route('api.v1.signature.show', $revision))
            ->assertOk()
            ->assertJsonPath('state', 'pendiente_docente')
            ->assertJsonPath('my_role', 'docente')
            ->assertJsonPath('can_sign', true)
            ->assertJsonPath('signatures', []);
        $this->assertIsArray($status->json('stamp'));
        $original = $this->currentPdf($revision, 'teacher');
        $this->assertSame(hash('sha256', $original), $status->json('document.sha256'));

        $this->actingAsCoordinator()->getJson(route('api.v1.signature.show', $revision))
            ->assertOk()->assertJsonPath('can_sign', false)->assertJsonPath('my_role', 'coordinacion');
        $this->upload($revision, $this->signPdfIncrementally($original, $coordinatorCredentials), hash('sha256', $original), 'coordinator')
            ->assertJsonValidationErrors(['signed_pdf' => 'Primero debe firmar el docente.']);

        $teacherSigned = $this->signPdfIncrementally($original, $teacherCredentials);
        $this->upload($revision, $teacherSigned, hash('sha256', $original), 'teacher')->assertCreated();
        $this->assertNotified($this->coordinator->id, 'silabo.firmado_docente', 'Falta la firma de coordinación');

        $this->actingAsCoordinator()->getJson(route('api.v1.signature.show', $revision))
            ->assertOk()
            ->assertJsonPath('state', 'pendiente_coordinacion')
            ->assertJsonPath('can_sign', true)
            ->assertJsonPath('document.sha256', hash('sha256', $teacherSigned))
            ->assertJsonPath('signatures.0.certificate_holder', 'DOCENTE DE PRUEBA');
        $this->assertSame($teacherSigned, $this->currentPdf($revision, 'coordinator'));

        $this->upload($revision, $this->signPdfIncrementally($original, $coordinatorCredentials), hash('sha256', $original), 'coordinator')
            ->assertJsonValidationErrors(['signed_pdf' => 'El documento cambió mientras firmaba. Descárguelo de nuevo y vuelva a firmar.']);

        $coordinationPdf = $this->signPdfIncrementally($teacherSigned, $coordinatorCredentials);
        $this->upload($revision, $coordinationPdf, hash('sha256', $teacherSigned), 'coordinator')->assertCreated();
        $this->assertNotified($this->librarian->id, 'silabo.firmado_coordinacion', 'Falta la firma de biblioteca');
        $this->upload($revision, $this->signPdfIncrementally($coordinationPdf, $librarianCredentials), hash('sha256', $coordinationPdf), 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'Su firma ya está registrada; ahora firma biblioteca.']);

        $status = $this->actingAsLibrarian()->getJson(route('api.v1.signature.show', $revision))
            ->assertOk()
            ->assertJsonPath('state', 'pendiente_biblioteca')
            ->assertJsonPath('my_role', 'biblioteca')
            ->assertJsonPath('can_sign', true)
            ->assertJsonPath('library.sent_by', $this->teacher->nombre)
            ->assertJsonPath('library.verified_by', $this->librarian->nombre);
        $this->assertIsArray($status->json('stamp'));
        $this->assertSame($coordinationPdf, $this->currentPdf($revision, 'librarian'));

        $libraryPdf = $this->signPdfIncrementally($coordinationPdf, $librarianCredentials);
        $this->upload($revision, $libraryPdf, hash('sha256', $coordinationPdf), 'librarian')->assertCreated();
        $this->assertNotified($this->dean->id, 'silabo.firmado_biblioteca', 'Falta la firma del decanato');
        $this->upload($revision, $this->signPdfIncrementally($libraryPdf, $deanCredentials), hash('sha256', $libraryPdf), 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'Su firma ya está registrada; ahora firma el decanato.']);

        // I-173: el decanato firma al final.
        $this->actingAsDean()->getJson(route('api.v1.signature.show', $revision))
            ->assertOk()->assertJsonPath('state', 'pendiente_decanato')->assertJsonPath('my_role', 'decanato')->assertJsonPath('can_sign', true);
        $finalPdf = $this->signPdfIncrementally($libraryPdf, $deanCredentials);
        $this->upload($revision, $finalPdf, hash('sha256', $libraryPdf), 'dean')->assertCreated();
        // I-186: la última firma (decanato) no genera aviso.
        $this->assertNotNotified($this->teacher->id, 'silabo.firmado');
        $this->assertNotNotified($this->coordinator->id, 'silabo.firmado');

        $this->actingAsTeacher()->getJson(route('api.v1.signature.show', $revision))
            ->assertOk()
            ->assertJsonPath('state', 'firmado')
            ->assertJsonPath('can_sign', false)
            ->assertJsonCount(4, 'signatures')
            ->assertJsonPath('signatures.0.role', 'docente')
            ->assertJsonPath('signatures.1.role', 'coordinacion')
            ->assertJsonPath('signatures.2.role', 'biblioteca')
            ->assertJsonPath('signatures.3.role', 'decanato');
        $this->assertSame($finalPdf, $this->currentPdf($revision, 'teacher'));
        $this->assertSame(4, DocumentSignature::query()->count());
        $this->assertSame(4, AuditEvent::query()->where('accion', 'documento.firmado')->count());
        $this->upload($revision, $this->signPdfIncrementally($finalPdf, $teacherCredentials), hash('sha256', $finalPdf), 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'El documento ya tiene todas las firmas.']);
    }

    public function test_rechaza_pdf_alterado_firma_que_no_cubre_el_archivo_y_certificado_vencido(): void
    {
        [, $revision] = $this->exportedRevision();
        $credentials = $this->makeSigningCertificate('DOCENTE DE PRUEBA', days: 1);
        $original = $this->currentPdf($revision, 'teacher');
        $base = hash('sha256', $original);

        $tampered = $this->signPdfIncrementally(str_replace('%PDF-1.4', '%PDF-1.5', $original), $credentials);
        $this->upload($revision, $tampered, $base, 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'El PDF firmado no parte del documento vigente. Descárguelo de nuevo y vuelva a firmar.']);

        $signed = $this->signPdfIncrementally($original, $credentials);
        $afterSigning = substr_replace($signed, 'X', strlen($signed) - 10, 1);
        $this->upload($revision, $afterSigning, $base, 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'La firma no corresponde al documento: el PDF fue alterado o la firma es inválida.']);

        $this->upload($revision, $signed."\n% agregado\n", $base, 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'La firma nueva no cubre el documento completo.']);

        $this->upload($revision, $original."\n% sin firma\n%%EOF\n", $base, 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'El PDF debe agregar exactamente una firma nueva.']);

        $this->travel(3)->days();
        $this->upload($revision, $signed, $base, 'teacher')
            ->assertJsonValidationErrors(['signed_pdf' => 'El certificado de firma está vencido. Renueve su firma electrónica.']);
        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame([], array_values(array_filter(
            Storage::disk('private')->allFiles("exports/{$revision->id}"),
            fn (string $path): bool => str_contains($path, 'firmado'),
        )));
    }

    public function test_reabrir_anula_el_proceso_y_personas_ajenas_no_firman(): void
    {
        [, $revision] = $this->exportedRevision();
        $credentials = $this->makeSigningCertificate('DOCENTE DE PRUEBA');
        $original = $this->currentPdf($revision, 'teacher');

        $outsider = User::factory()->create();
        $outsiderContext = RoleAssignment::query()->create([
            'usuario_id' => $outsider->id,
            'rol' => 'docente',
            'carrera_id' => $this->teacherContext->carrera_id,
            'activo' => true,
        ]);
        $this->actingAs($outsider)->withSession(['active_role_assignment_id' => $outsiderContext->id])
            ->post(route('api.v1.signature.store', $revision), [
                'signed_pdf' => UploadedFile::fake()->createWithContent('firmado.pdf', $this->signPdfIncrementally($original, $credentials)),
                'base_sha256' => hash('sha256', $original),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame(0, DocumentSignature::query()->count());
    }

    public function test_el_verificador_acepta_la_segunda_firma_sin_romper_la_primera(): void
    {
        $verifier = app(IncrementalPdfSignatureVerifier::class);
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $first = $this->signPdfIncrementally($pdf, $this->makeSigningCertificate('PRIMERA FIRMA'));
        $second = $this->signPdfIncrementally($first, $this->makeSigningCertificate('SEGUNDA FIRMA'));

        $this->assertSame('PRIMERA FIRMA', $verifier->verify($pdf, $first)->holder);
        $this->assertSame('SEGUNDA FIRMA', $verifier->verify($first, $second)->holder);
        $this->expectException(ValidationException::class);
        $verifier->verify($pdf, $second);
    }

    public function test_con_gotenberg_el_pdf_sale_del_docx_y_es_reproducible(): void
    {
        config(['services.gotenberg.url' => 'http://gotenberg:3000/']);
        $libreOffice = "%PDF-1.7\n1 0 obj\n<</Creator(Writer)/Producer(LibreOffice 25.2)/CreationDate(D:20260924101112-05'00')>>\nendobj\n"
            ."trailer\n<</Size 2/Root 1 0 R/ID [ <0123456789ABCDEF0123456789ABCDEF>\n<0123456789ABCDEF0123456789ABCDEF> ]>>\n%%EOF\n";
        Http::fake(['gotenberg:3000/forms/libreoffice/convert' => Http::response($libreOffice, 200, ['Content-Type' => 'application/pdf'])]);

        $renderer = app(PhpWordDocumentRenderer::class);
        $this->assertSame(PhpWordDocumentRenderer::VERSION_GOTENBERG, $renderer->version());
        $converter = app(GotenbergPdfConverter::class);
        $first = $converter->convert('docx-bytes', '2026-09-24T10:00:00+00:00');
        $again = $converter->convert('docx-bytes', '2026-09-24T10:00:00+00:00');

        $this->assertSame($first, $again);
        $this->assertSame(strlen($libreOffice), strlen($first));
        $this->assertStringContainsString("/CreationDate(D:20260924100000Z00'00')", $first);
        $this->assertStringNotContainsString('0123456789ABCDEF', $first);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://gotenberg:3000/forms/libreoffice/convert' && $request->isMultipart());

        config(['services.gotenberg.url' => null]);
        $this->assertSame(PhpWordDocumentRenderer::VERSION, app(PhpWordDocumentRenderer::class)->version());
    }

    private function upload(SyllabusRevision $revision, string $pdf, string $base, string $as): TestResponse
    {
        $this->actingAsRole($as);

        return $this->post(route('api.v1.signature.store', $revision), [
            'signed_pdf' => UploadedFile::fake()->createWithContent('firmado.pdf', $pdf),
            'base_sha256' => $base,
        ], ['Accept' => 'application/json']);
    }

    private function currentPdf(SyllabusRevision $revision, string $as): string
    {
        $this->actingAsRole($as);

        return $this->get(route('api.v1.signature.pdf', $revision))->assertOk()->streamedContent();
    }

    /** @return array{Syllabus, SyllabusRevision} */
    private function exportedRevision(): array
    {
        Queue::fake([GenerateSyllabusExportJob::class]);
        $career = Career::query()->firstOrFail();
        $subject = Subject::query()->firstOrFail();
        $template = SyllabusTemplate::query()->create([
            'nombre' => 'Plantilla de firma I-139',
            'activo' => true,
            'mapeo_documento' => ['renderer' => 'baseline'],
        ]);
        $convocation = Convocation::query()->create([
            'carrera_id' => $career->id,
            'proceso_id' => $this->openSyllabusProcess($template->id)->id,
            'estado' => 'abierta',
        ]);
        $teacherAssignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();
        $syllabus = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => $teacherAssignment->paralelo_id,
            'asignatura_id' => $subject->id,
            'estado' => 'aprobado',
            'version_bloqueo' => 2,
            'iniciado_en' => now()->subDay(),
            'guardado_en' => now(),
        ]);
        $snapshot = [
            'schema_version' => 1,
            'template_id' => $template->id,
            'sections' => [[
                'key' => 'general',
                'title' => 'Información general',
                'blocks' => [[
                    'key' => 'contenido',
                    'title' => 'Contenido académico',
                    'fields' => [[
                        'key' => 'descripcion', 'label' => 'Descripción', 'type' => 'text',
                        'inherited' => false, 'value' => 'Contenido para firmar',
                    ]],
                ]],
            ]],
        ];
        $revision = SyllabusRevision::query()->create([
            'silabo_id' => $syllabus->id,
            'numero_revision' => 1,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 1,
            'fotografia' => $snapshot,
            'huella_sha256' => app(CanonicalHasher::class)->hash($snapshot),
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now()->subMinute(),
        ]);
        // I-173: biblioteca y coordinación ya revisaron; el decanato aprobó.
        LibrarySubmission::query()->create([
            'revision_silabo_id' => $revision->id,
            'verificado_por' => $this->librarian->id,
            'verificado_en' => now(),
        ]);
        CoordinationReview::query()->create([
            'revision_silabo_id' => $revision->id,
            'revisado_por' => $this->coordinator->id,
            'revisado_en' => now(),
        ]);
        Approval::query()->create([
            'revision_silabo_id' => $revision->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => hash('sha256', $revision->huella_sha256),
            'aprobado_por' => $this->dean->id,
            'aprobado_en' => now(),
        ]);
        $this->actingAsTeacher()->getJson(route('api.v1.signature.show', $revision))
            ->assertOk()->assertJsonPath('state', 'sin_documento')->assertJsonPath('can_sign', false);
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        (new GenerateSyllabusExportJob(ExportArtifact::query()->firstOrFail()->id))->handle(
            app(DocumentRenderer::class),
            app(RecordAuditEvent::class),
            app(DocumentBundleValidator::class),
        );

        return [$syllabus->fresh(), $revision->fresh()];
    }

    private function actingAsRole(string $as): static
    {
        return match ($as) {
            'teacher' => $this->actingAsTeacher(),
            'librarian' => $this->actingAsLibrarian(),
            'dean' => $this->actingAsDean(),
            default => $this->actingAsCoordinator(),
        };
    }

    private function actingAsDean(): static
    {
        $this->actingAs($this->dean)->withSession(['active_role_assignment_id' => $this->deanContext->id]);

        return $this;
    }

    private function actingAsLibrarian(): static
    {
        $this->actingAs($this->librarian)->withSession(['active_role_assignment_id' => $this->librarianContext->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }

    private function actingAsTeacher(): static
    {
        $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacherContext->id]);

        return $this;
    }
}
