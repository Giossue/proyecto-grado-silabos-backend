<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Application\DocumentBundleValidator;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\BibliographyCorrection;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsPdfIncrementally;
use Tests\TestCase;

class LibraryReviewTest extends TestCase
{
    use AssertsInternalNotifications;
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use SignsPdfIncrementally;

    /** @var array<string, array{User, RoleAssignment}> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        foreach (['admin' => 'admin', 'coordinator' => 'coordinador', 'teacher' => 'docente', 'librarian' => 'bibliotecario'] as $key => $mail) {
            $user = User::query()->where('correo_electronico', "{$mail}@silabos.test")->firstOrFail();
            $this->people[$key] = [$user, $user->roleAssignments()->firstOrFail()];
        }
    }

    public function test_bibliotecario_es_un_rol_sin_carrera_que_entra_directo(): void
    {
        $assignment = $this->people['librarian'][1];
        $this->assertSame(RoleCode::Librarian->value, $assignment->rol);
        $this->assertNull($assignment->carrera_id);

        $this->as('admin')->postJson(route('api.v1.admin.users.store'), [
            'nombre' => 'Bibliotecaria Nueva',
            'correo_electronico' => 'biblioteca.nueva@silabos.test',
            'role_code' => RoleCode::Librarian->value,
        ])->assertSuccessful();
        $this->assertDatabaseHas('asignaciones_rol', ['rol' => 'bibliotecario', 'carrera_id' => null]);

        $this->actingAs($this->people['librarian'][0])->getJson(route('api.v1.session.show'))
            ->assertOk()
            ->assertJsonPath('active_role.code', 'bibliotecario')
            ->assertJsonPath('active_role.career_id', null);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.dashboard.show'))->assertOk()
            ->assertJsonPath('metrics.0.key', 'library_pending')->assertJsonPath('setup', null);
        $this->as('coordinator')->getJson(route('api.v1.library.reviews.index'))->assertForbidden();
        $this->as('librarian')->getJson(route('api.v1.coordination.reviews.index'))->assertForbidden();
        $this->as('librarian')->getJson(route('api.v1.teacher.syllabi.index'))->assertForbidden();
    }

    public function test_biblioteca_comenta_y_el_docente_corrige_solo_la_bibliografia(): void
    {
        [$syllabus, $revision] = $this->signedByTeacherAndCoordination();
        $this->as('teacher')->postJson(route('api.v1.library.submission.store', $revision))->assertCreated();

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))
            ->assertOk()
            ->assertJsonPath('reviews.total', 1)
            ->assertJsonPath('reviews.data.0.revision_id', $revision->id)
            ->assertJsonPath('reviews.data.0.state', 'pendiente')
            ->assertJsonPath('reviews.data.0.teachers.0', $this->people['teacher'][0]->nombre);
        $detail = $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('review.can_comment', true)
            ->assertJsonPath('review.sections.0.key', 'bibliografia')
            ->assertJsonCount(1, 'review.sections');
        $this->assertNotEmpty($detail->json('review.template_variables'));

        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'comment' => '', 'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('comment');
        $key = (string) Str::uuid();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'comment' => 'No tenemos «Clean Architecture»; proponga otra edición.', 'idempotency_key' => $key,
        ])->assertCreated();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'comment' => 'No tenemos «Clean Architecture»; proponga otra edición.', 'idempotency_key' => $key,
        ])->assertCreated();
        $this->assertDatabaseCount('reaperturas', 1);
        $this->assertDatabaseHas('reaperturas', ['origen_reapertura' => 'biblioteca', 'reabierto_por' => $this->people['librarian'][0]->id]);
        $this->assertNotified($this->people['teacher'][0]->id, 'biblioteca.comentado', 'Corríjala y reenvíela');
        $this->assertSame('correccion_solicitada', $syllabus->fresh()->estado);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertJsonPath('reviews.data.0.state', 'comentado');
        $this->as('teacher')->getJson(route('api.v1.signature.show', $revision))->assertJsonPath('state', 'anulado');

        $this->as('admin')->postJson(route('api.v1.admin.processes.transition', [SyllabusProcess::query()->firstOrFail(), 'cerrar']))->assertSuccessful();
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.library_correction', true)
            ->assertJsonPath('syllabus.editable', true)
            ->assertJsonPath('syllabus.reopening.origin', 'biblioteca')
            ->assertJsonPath('syllabus.library_comments.0.comment', 'No tenemos «Clean Architecture»; proponga otra edición.');

        $bibliography = $this->field('bibliografia');
        $other = FieldDefinition::query()->where('editable_docente', true)->where('heredado', false)->get()
            ->first(fn (FieldDefinition $field): bool => ! BibliographyCorrection::includes($field) && $field->tipo === 'texto_libre');
        $this->assertNotNull($other);
        $this->as('teacher')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $other]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->freeTextValue('Cambio no pedido'),
        ])->assertJsonValidationErrors('field');
        $this->as('teacher')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $bibliography]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->freeTextValue('Martin, R. Arquitectura limpia (2.ª ed.).'),
        ])->assertOk();

        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $corrected = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->firstOrFail();
        $this->assertSame(2, $corrected->numero_revision);
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $approval = Approval::query()->where('revision_silabo_id', $corrected->id)->firstOrFail();
        $base = Approval::query()->where('revision_silabo_id', $revision->id)->firstOrFail();
        $this->assertSame($base->id, $approval->aprobacion_base_id);
        $this->assertSame($base->aprobado_por, $approval->aprobado_por);
        $this->assertNotified($this->people['coordinator'][0]->id, 'silabo.bibliografia_corregida');

        $before = $revision->fotografia;
        $after = $corrected->fotografia;
        $this->assertNotSame(BibliographyCorrection::sections($before), BibliographyCorrection::sections($after));
        $strip = fn (array $snapshot): array => array_values(array_filter($snapshot['sections'], fn (array $section): bool => $section['key'] !== 'bibliografia'));
        $this->assertSame($strip($before), $strip($after));
        $this->assertSame($before['template_variables'], $after['template_variables']);
        $this->assertStringContainsString('Arquitectura limpia', json_encode(BibliographyCorrection::sections($after), JSON_UNESCAPED_UNICODE));

        $this->as('teacher')->getJson(route('api.v1.signature.show', $corrected))->assertJsonPath('state', 'sin_documento');
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertJsonPath('syllabus.library_correction', false)
            ->assertJsonPath('syllabus.approved_revision_id', $corrected->id);
    }

    public function test_el_envio_solo_ocurre_tras_las_dos_firmas_y_biblioteca_no_ve_lo_no_enviado(): void
    {
        [, $revision] = $this->approvedRevision();
        $this->as('teacher')->postJson(route('api.v1.library.submission.store', $revision))->assertJsonValidationErrors('revision');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))->assertNotFound();
        $this->as('librarian')->getJson(route('api.v1.signature.show', $revision))->assertForbidden();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'comment' => 'Sin libros', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    /** @return array{Syllabus, SyllabusRevision} */
    private function signedByTeacherAndCoordination(): array
    {
        [$syllabus, $revision] = $this->approvedRevision();
        $this->exportPdf($revision);
        $original = $this->as('teacher')->get(route('api.v1.signature.pdf', $revision))->assertOk()->streamedContent();
        $teacherSigned = $this->signPdfIncrementally($original, $this->makeSigningCertificate('DOCENTE DE PRUEBA'));
        $this->upload($revision, $teacherSigned, $original, 'teacher')->assertCreated();
        $coordinationSigned = $this->signPdfIncrementally($teacherSigned, $this->makeSigningCertificate('COORDINACION DE PRUEBA'));
        $this->upload($revision, $coordinationSigned, $teacherSigned, 'coordinator')->assertCreated();

        return [$syllabus, $revision];
    }

    /** @return array{Syllabus, SyllabusRevision} */
    private function approvedRevision(): array
    {
        $this->as('admin')->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-165']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->as('admin')->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->as('coordinator')->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $this->assertSame(1, Convocation::query()->count());
        $syllabus = Syllabus::query()->firstOrFail();
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        foreach (FieldDefinition::query()->where('heredado', false)->get() as $field) {
            $this->as('teacher')->patchJson(
                route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
                ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->valueFor($field)],
            )->assertOk();
        }
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revision = SyllabusRevision::query()->firstOrFail();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        return [$syllabus->fresh(), $revision->fresh()];
    }

    private function exportPdf(SyllabusRevision $revision): void
    {
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('teacher')->postJson(route('api.v1.documents.store', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        (new GenerateSyllabusExportJob(ExportArtifact::query()->latest('solicitado_en')->firstOrFail()->id))->handle(
            app(DocumentRenderer::class),
            app(RecordAuditEvent::class),
            app(DocumentBundleValidator::class),
        );
    }

    private function upload(SyllabusRevision $revision, string $pdf, string $base, string $as): TestResponse
    {
        return $this->as($as)->post(route('api.v1.signature.store', $revision), [
            'signed_pdf' => UploadedFile::fake()->createWithContent('firmado.pdf', $pdf),
            'base_sha256' => hash('sha256', $base),
        ], ['Accept' => 'application/json']);
    }

    private function valueFor(FieldDefinition $field): mixed
    {
        $option = collect($field->opciones ?? [])->map(fn (mixed $item): string => is_array($item) ? (string) ($item['value'] ?? 'opcion') : (string) $item)->first();

        return match ($field->tipo) {
            'texto_libre' => $this->freeTextValue("Contenido {$field->clave}"),
            'booleano' => true,
            'numero' => 1,
            'fecha' => now()->toDateString(),
            'seleccion_unica' => $option,
            'seleccion_multiple' => [$option],
            default => "Contenido {$field->clave}",
        };
    }

    private function field(string $key): FieldDefinition
    {
        return FieldDefinition::query()->where('clave', $key)->firstOrFail();
    }

    private function as(string $who): static
    {
        [$user, $assignment] = $this->people[$who];
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);

        return $this;
    }
}
