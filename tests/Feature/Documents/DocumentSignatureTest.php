<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Application\DocumentBundleValidator;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\SigningStatement;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\TestCase;
use ZipArchive;

class DocumentSignatureTest extends TestCase
{
    use AssertsInternalNotifications;
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use SignsStatements;

    private const LIBREOFFICE_PDF = "%PDF-1.7\n1 0 obj\n<</Creator(Writer)/Producer(LibreOffice 25.2)/CreationDate(D:20260924101112-05'00')>>\nendobj\n"
        ."trailer\n<</Size 2/Root 1 0 R/ID [ <0123456789ABCDEF0123456789ABCDEF>\n<0123456789ABCDEF0123456789ABCDEF> ]>>\n%%EOF\n";

    /** @var array<string, array{User, RoleAssignment}> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('private');
        $this->configureFacultyLogos();
        foreach (['admin' => 'admin', 'coordinator' => 'coordinador', 'teacher' => 'docente', 'librarian' => 'bibliotecario'] as $key => $mail) {
            $user = User::query()->where('correo_electronico', "{$mail}@silabos.test")->firstOrFail();
            $this->people[$key] = [$user, $user->roleAssignments()->firstOrFail()];
        }
    }

    public function test_el_docente_firma_solo_en_el_primer_envio(): void
    {
        $syllabus = $this->validDraft();
        $expected = SigningStatement::for($syllabus->id, 'docente', $this->draftFingerprint($syllabus));

        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.signing-statement', $syllabus), ['version_bloqueo' => $syllabus->version_bloqueo])
            ->assertOk()->assertExactJson(['statement' => $expected, 'required' => true]);
        $this->assertStringStartsWith("silabos-ueb|silabo:{$syllabus->id}|accion:envio_docente|huella:", $expected);
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.signing-statement', $syllabus), ['version_bloqueo' => $syllabus->version_bloqueo + 5])
            ->assertConflict();
        $this->as('coordinator')->postJson(route('api.v1.teacher.syllabi.signing-statement', $syllabus), ['version_bloqueo' => $syllabus->version_bloqueo])
            ->assertForbidden();
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertJsonPath('syllabus.teacher_signature_required', true)->assertJsonPath('syllabus.signatures', []);

        $this->submit($syllabus, null)->assertJsonValidationErrors(['signature' => 'Firme con su firma electrónica.']);
        $this->assertDatabaseCount('revisiones_silabo', 0);

        $this->submit($syllabus, $this->decisionSignature($expected, $this->people['teacher'][0]->nombre))->assertSuccessful();
        $revision = SyllabusRevision::query()->firstOrFail();
        $signature = SyllabusSignature::query()->firstOrFail();
        $this->assertSame('docente', $signature->firmado_como);
        $this->assertSame($revision->id, $signature->revision_silabo_id);
        $this->assertSame($expected, $signature->declaracion_firmada);
        $this->assertSame($this->people['teacher'][0]->nombre, $signature->nombre_en_certificado);
        $this->assertStringEndsWith("huella:{$revision->huella_sha256}", $expected);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'silabo.firmado', 'recurso_id' => $signature->id, 'detalles->role' => 'docente']);
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertJsonPath('syllabus.teacher_signature_required', false)
            ->assertJsonPath('syllabus.signatures.0.role', 'docente')
            ->assertJsonPath('syllabus.signatures.0.name', $this->people['teacher'][0]->nombre);

        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.correction.store', $revision), [
            'justification' => 'Ajuste los resultados de aprendizaje.',
        ])->assertSuccessful();
        $syllabus->refresh();
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.signing-statement', $syllabus), ['version_bloqueo' => $syllabus->version_bloqueo])
            ->assertOk()->assertJsonPath('required', false);
        $this->submit($syllabus, null)->assertSuccessful();
        $this->assertDatabaseCount('revisiones_silabo', 2);
        $this->assertSame(1, SyllabusSignature::query()->count());
    }

    public function test_coordinacion_y_biblioteca_firman_al_decidir_y_el_pdf_lleva_los_tres_sellos(): void
    {
        Queue::fake([GenerateSyllabusExportJob::class]);
        [$syllabus, $revision] = $this->submitted();
        [$coordinator] = $this->people['coordinator'];
        [$librarian] = $this->people['librarian'];
        $coordinationStatement = SigningStatement::for($syllabus->id, 'coordinacion', $revision->huella_sha256);

        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('signing_statement', $coordinationStatement)
            ->assertJsonCount(1, 'signatures');
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision))
            ->assertJsonValidationErrors(['signature' => 'Firme con su firma electrónica.']);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'signature' => $this->decisionSignature(SigningStatement::for($syllabus->id, 'biblioteca', $revision->huella_sha256), $coordinator->nombre),
        ])->assertJsonValidationErrors(['signature' => 'La declaración firmada no corresponde. Vuelva a firmar.']);
        $this->assertDatabaseCount('revisiones_coordinacion', 0);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'signature' => $this->decisionSignature($coordinationStatement, $coordinator->nombre),
        ])->assertSuccessful();
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertJsonPath('signing_statement', null)->assertJsonPath('signatures.1.role', 'coordinacion');

        $libraryStatement = SigningStatement::for($syllabus->id, 'biblioteca', $revision->huella_sha256);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertOk()->assertJsonPath('review.signing_statement', $libraryStatement)->assertJsonCount(2, 'review.signatures');
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision))
            ->assertJsonValidationErrors(['signature' => 'Firme con su firma electrónica.']);
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), [
            'signature' => $this->decisionSignature($libraryStatement, $librarian->nombre),
        ])->assertCreated();
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->assertSame(['docente', 'coordinacion', 'biblioteca'], SyllabusSignature::query()->orderBy('firmado_en')->orderByRaw("firmado_como = 'biblioteca', firmado_como = 'coordinacion'")->pluck('firmado_como')->all());
        $this->assertSame(3, DB::table('eventos_auditoria')->where('accion', 'silabo.firmado')->count());

        config(['services.gotenberg.url' => 'http://gotenberg:3000/']);
        $sentDocx = null;
        Http::fake(function (ClientRequest $request) use (&$sentDocx) {
            foreach ($request->data() as $part) {
                if (($part['name'] ?? null) === 'files') {
                    $sentDocx = $part['contents'];
                }
            }

            return Http::response(self::LIBREOFFICE_PDF, 200, ['Content-Type' => 'application/pdf']);
        });
        $artifact = ExportArtifact::query()->where('revision_silabo_id', $revision->id)->firstOrFail();
        (new GenerateSyllabusExportJob($artifact->id))->handle(app(DocumentRenderer::class), app(RecordAuditEvent::class), app(DocumentBundleValidator::class));
        $artifact->refresh();
        $this->assertSame('completado', $artifact->estado);
        $this->assertNull($artifact->objeto_docx_id);
        $this->assertNotNull($artifact->objeto_pdf_id);
        $this->assertSame([], array_values(array_filter(Storage::disk('private')->allFiles(), fn (string $path): bool => str_ends_with($path, '.docx'))));
        $this->assertIsString($sentDocx);
        $text = $this->docxText($sentDocx);
        foreach ([[$this->people['teacher'][0], 'Docente'], [$coordinator, 'Coordinación'], [$librarian, 'Biblioteca']] as [$signer, $role]) {
            $this->assertStringContainsString("Firmado electrónicamente por {$signer->nombre} · {$role} · ", $text);
        }
        $this->assertSame(3, substr_count($text, 'certificado N.º '));
        $this->assertNotified($this->people['teacher'][0]->id, 'silabo.aprobado', 'Ya puede descargar el PDF firmado');
        $this->assertNotNotified($coordinator->id, 'silabo.aprobado');

        $this->as('teacher')->getJson(route('api.v1.documents.show', $revision))
            ->assertOk()
            ->assertJsonPath('artifacts.0.files', ['pdf_size' => strlen((string) Storage::disk('private')->get("exports/{$revision->id}/{$artifact->id}/syllabus.pdf"))])
            ->assertJsonMissingPath('artifacts.0.signed');
        $download = $this->as('coordinator')->get(route('api.v1.documents.download', $artifact))->assertOk();
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->as('teacher')->get("/api/v1/exports/{$artifact->id}/docx")->assertNotFound();
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonCount(3, 'syllabus.signatures');
    }

    public function test_rechaza_firmas_invalidas_vencidas_ajenas_o_de_entidades_no_reconocidas(): void
    {
        $syllabus = $this->validDraft();
        [$teacher] = $this->people['teacher'];
        $statement = SigningStatement::for($syllabus->id, 'docente', $this->draftFingerprint($syllabus));
        $own = $this->makeSigningCertificate($teacher->nombre);

        $this->submit($syllabus, ['statement' => $statement, 'cms' => 'no-es-base64'])
            ->assertJsonValidationErrors(['signature' => 'La firma no es válida.']);
        $this->submit($syllabus, ['statement' => $statement])
            ->assertJsonValidationErrors(['signature' => 'La firma no es válida.']);
        $this->submit($syllabus, $this->decisionSignature(SigningStatement::for($syllabus->id, 'docente', str_repeat('a', 64)), $teacher->nombre))
            ->assertJsonValidationErrors(['signature' => 'La declaración firmada no corresponde. Vuelva a firmar.']);
        $this->submit($syllabus, ['statement' => $statement, 'cms' => $this->signStatement($statement.'|otra', $own)])
            ->assertJsonValidationErrors(['signature' => 'La firma no corresponde a la declaración.']);
        $this->submit($syllabus, ['statement' => $statement, 'cms' => $this->signStatement($statement, $this->makeSigningCertificate('MARIA LUISA GOMEZ TORRES'))])
            ->assertJsonValidationErrors(['signature' => "El certificado es de «MARIA LUISA GOMEZ TORRES», no de {$teacher->nombre}."]);

        $bundle = (string) tempnam(sys_get_temp_dir(), 'silabos-ca-');
        file_put_contents($bundle, $this->makeCertificateAuthority()['pem']);
        config(['signatures.trusted_ca_bundle' => $bundle]);
        $this->submit($syllabus, ['statement' => $statement, 'cms' => $this->signStatement($statement, $own)])
            ->assertJsonValidationErrors(['signature' => 'El certificado no es de una entidad reconocida.']);
        @unlink($bundle);
        config(['signatures.trusted_ca_bundle' => null]);

        $shortLived = $this->signStatement($statement, $this->makeSigningCertificate($teacher->nombre, days: 1));
        $this->travel(3)->days();
        $this->submit($syllabus, ['statement' => $statement, 'cms' => $shortLived])
            ->assertJsonValidationErrors(['signature' => 'El certificado está vencido.']);

        $this->assertDatabaseCount('firmas_silabo', 0);
        $this->assertDatabaseCount('revisiones_silabo', 0);
        $this->assertSame('borrador', $syllabus->fresh()->estado);
    }

    public function test_reiniciar_borra_las_firmas_y_las_firmas_no_se_modifican(): void
    {
        Queue::fake([GenerateSyllabusExportJob::class]);
        [$syllabus] = $this->submitted();
        $signature = SyllabusSignature::query()->firstOrFail();

        try {
            DB::transaction(fn () => DB::table('firmas_silabo')->where('firma_silabo_id', $signature->id)->update(['nombre_en_certificado' => 'OTRA PERSONA']));
            $this->fail('PostgreSQL permitió modificar una firma.');
        } catch (QueryException) {
            $this->assertSame($signature->nombre_en_certificado, $signature->fresh()?->nombre_en_certificado);
        }

        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.reset', $syllabus))->assertSuccessful();
        $this->assertDatabaseCount('firmas_silabo', 0);
        $fresh = Syllabus::query()->where('paralelo_id', $syllabus->paralelo_id)->firstOrFail();
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $fresh))
            ->assertJsonPath('syllabus.teacher_signature_required', true)->assertJsonPath('syllabus.signatures', []);
    }

    public function test_ya_no_existen_la_firma_secuencial_del_pdf_ni_la_descarga_word(): void
    {
        foreach (['api.v1.signature.show', 'api.v1.signature.pdf', 'api.v1.signature.store'] as $name) {
            $this->assertFalse(Route::has($name));
        }
        $revision = (string) Str::uuid();
        $this->as('teacher')->getJson("/api/v1/revisions/{$revision}/signature")->assertNotFound();
        $this->as('teacher')->postJson("/api/v1/revisions/{$revision}/signature")->assertNotFound();
        $this->as('teacher')->getJson("/api/v1/revisions/{$revision}/signature/pdf")->assertNotFound();
        $this->assertStringEndsWith('/pdf', route('api.v1.documents.download', (string) Str::uuid()));
    }

    /** @param array<string, string>|null $signature */
    private function submit(Syllabus $syllabus, ?array $signature): TestResponse
    {
        return $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), array_filter([
            'version_bloqueo' => $syllabus->fresh()?->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
            'signature' => $signature,
        ], fn (mixed $value): bool => $value !== null));
    }

    /** @return array{Syllabus, SyllabusRevision} */
    private function submitted(): array
    {
        $syllabus = $this->validDraft();
        $this->submit($syllabus, $this->teacherSignature($syllabus, $this->people['teacher'][0]))->assertSuccessful();

        return [$syllabus->fresh() ?? $syllabus, SyllabusRevision::query()->where('silabo_id', $syllabus->id)->latest('numero_revision')->firstOrFail()];
    }

    private function validDraft(): Syllabus
    {
        $this->as('admin')->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla de firmas']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->as('admin')->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();
        $this->as('coordinator')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $this->assertSame(1, Convocation::query()->count());
        $syllabus = Syllabus::query()->firstOrFail();
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        foreach (FieldDefinition::query()->where('quien_llena', 'docente')->get() as $field) {
            $this->as('teacher')->patchJson(
                route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
                ['version_bloqueo' => $syllabus->fresh()?->version_bloqueo, 'value' => $this->answeredValue($field, "Contenido {$field->clave}")],
            )->assertOk();
        }

        return $syllabus->fresh() ?? $syllabus;
    }

    private function draftFingerprint(Syllabus $syllabus): string
    {
        $statement = $this->teacherSignature($syllabus, $this->people['teacher'][0])['statement'];

        return substr($statement, (int) strrpos($statement, ':') + 1);
    }

    private function docxText(string $docx): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'silabos-docx-');
        file_put_contents($path, $docx);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function as(string $who): static
    {
        [$user, $assignment] = $this->people[$who];
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);

        return $this;
    }
}
