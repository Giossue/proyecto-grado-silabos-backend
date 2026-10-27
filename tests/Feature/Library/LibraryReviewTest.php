<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Application\DocumentBundleValidator;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
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

    /**
     * @var array<string, array{User, RoleAssignment}>
     */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['signatures.require_holder_match' => false]);
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
            'primer_nombre' => 'Bibliotecaria', 'segundo_nombre' => 'Luz', 'primer_apellido' => 'Nueva', 'segundo_apellido' => 'Prueba',
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

    public function test_una_convocatoria_con_silabos_enviados_no_se_elimina_y_biblioteca_la_sigue_viendo(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()->assertJsonPath('reviews.total', 1);

        $process = $syllabus->convocation->process()->firstOrFail();
        $this->as('admin')->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process']);

        $this->assertDatabaseHas('convocatorias_universidad', [
            'convocatoria_universidad_id' => $process->id,
            'estado_convocatoria_universidad' => SyllabusProcess::STATE_OPEN,
        ]);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()->assertJsonPath('reviews.total', 1);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))->assertOk();
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 1);
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $revision->id]);
    }

    public function test_cada_bibliotecario_filtra_su_bandeja_por_facultad(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $own = $syllabus->convocation->career()->firstOrFail()->facultad_id;
        $other = Faculty::query()->create(['nombre' => 'Facultad de Ciencias de la Salud', 'campus' => 'Campus Matriz Dr. Gabriel Galarza López', 'activo' => true]);

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => $own]))
            ->assertOk()->assertJsonPath('reviews.total', 1)->assertJsonPath('filters.faculty', $own)
            ->assertJsonFragment(['id' => $other->id, 'name' => 'Facultad de Ciencias de la Salud']);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => $other->id]))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors(['faculty']);
    }

    public function test_biblioteca_revisa_tras_coordinacion_y_devuelve_al_docente_que_reenvia_directo(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->assertNotified($this->people['coordinator'][0]->id, 'silabo.enviado', 'coordinación lo revisa primero');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->assertNotified($this->people['librarian'][0]->id, 'coordinacion.aprobado', 'Falta que biblioteca apruebe');

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))
            ->assertOk()
            ->assertJsonPath('reviews.total', 1)
            ->assertJsonPath('reviews.data.0.revision_id', $revision->id)
            ->assertJsonPath('reviews.data.0.state', 'pendiente')
            ->assertJsonPath('reviews.data.0.teachers.0', $this->people['teacher'][0]->nombre);
        $detail = $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('review.can_comment', true)
            ->assertJsonPath('review.can_verify', true)
            ->assertJsonPath('review.sections.0.key', 'bibliografia')
            ->assertJsonCount(1, 'review.sections');
        $this->assertNotEmpty($detail->json('review.template_variables'));
        $this->assertStringContainsString('Contenido bibliografia', json_encode($detail->json('review.sections'), JSON_THROW_ON_ERROR));
        FieldValue::query()->where('asignatura_id', $syllabus->subject->id)
            ->update(['valor' => FreeText::fromText('Referencia posterior')]);
        $this->assertSame($detail->json('review.sections'), $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))->assertOk()->json('review.sections'));
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.correction.store', $revision), [
            'justification' => 'Coordinación ya aprobó esta revisión.',
        ])->assertJsonValidationErrors('revision');

        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => [], 'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('reasons');
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'comment' => 'Texto libre', 'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('reasons');
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['inventado'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('reasons.0');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.comment_reasons.0.code', 'libro_no_disponible');
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['libro_no_disponible', 'codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertDatabaseHas('revisiones_silabo', ['revision_silabo_id' => $revision->id, 'correccion_etapa' => 'biblioteca']);
        $this->assertNotified($this->people['teacher'][0]->id, 'silabo.correccion_solicitada', 'Corríjalo y reenvíelo');
        $this->assertSame('correccion_solicitada', $syllabus->fresh()->estado);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertJsonPath('reviews.data.0.state', 'comentado');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.can_comment', false)
            ->assertJsonPath('review.comments.0.comment', 'El libro no está disponible en biblioteca; El código del libro es incorrecto.');
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertJsonPath('syllabus.review_comments.0.stage', 'biblioteca')
            ->assertJsonPath('syllabus.review_comments.0.comment', 'El libro no está disponible en biblioteca; El código del libro es incorrecto.');

        $other = FieldDefinition::query()->where('quien_llena', 'docente')
            ->where('clave', '!=', 'bibliografia')->firstOrFail();
        $this->as('teacher')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $other]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->answeredValue($other, 'Ajuste general'),
        ])->assertOk();
        $this->as('teacher')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $this->field('bibliografia')]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->answeredValue($this->field('bibliografia'), 'Martin, R. Arquitectura limpia (2.ª ed.).'),
        ])->assertOk();
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $corrected = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->firstOrFail();
        $this->assertSame(2, $corrected->numero_revision);
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $corrected->id]);
        $this->assertDatabaseMissing('revisiones_coordinacion', ['revision_silabo_id' => $corrected->id]);
        $this->assertNotified($this->people['librarian'][0]->id, 'silabo.reenviado_biblioteca', 'reenvió a biblioteca');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.revision_id', $corrected->id);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'anulado']))->assertJsonPath('reviews.total', 0);
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
    }

    public function test_coordinacion_aprueba_primero_biblioteca_aprueba_y_firman_docente_coordinacion_y_biblioteca(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'coordinacion');
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision))->assertForbidden();
        $this->as('librarian')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();

        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');

        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision))->assertCreated();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision))->assertCreated();
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->assertSame($this->people['librarian'][0]->id, Approval::query()->firstOrFail()->aprobado_por);
        $this->assertNotified($this->people['teacher'][0]->id, 'silabo.aprobado');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.state', 'por_firmar')
            ->assertJsonPath('review.can_comment', false)
            ->assertJsonPath('review.verified_by', $this->people['librarian'][0]->nombre);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('revision');

        (new GenerateSyllabusExportJob(ExportArtifact::query()->latest('solicitado_en')->firstOrFail()->id))->handle(
            app(DocumentRenderer::class),
            app(RecordAuditEvent::class),
            app(DocumentBundleValidator::class),
        );
        $original = $this->as('teacher')->get(route('api.v1.signature.pdf', $revision))->assertOk()->streamedContent();
        $teacherSigned = $this->signPdfIncrementally($original, $this->makeSigningCertificate('DOCENTE DE PRUEBA'));
        $this->upload($revision, $teacherSigned, $original, 'teacher')->assertCreated();
        $coordinationSigned = $this->signPdfIncrementally($teacherSigned, $this->makeSigningCertificate('COORDINACION DE PRUEBA'));
        $this->upload($revision, $coordinationSigned, $teacherSigned, 'coordinator')->assertCreated();
        $this->assertNotified($this->people['librarian'][0]->id, 'silabo.firmado_coordinacion');
        $librarySigned = $this->signPdfIncrementally($coordinationSigned, $this->makeSigningCertificate('BIBLIOTECA DE PRUEBA'));
        $this->upload($revision, $librarySigned, $coordinationSigned, 'librarian')->assertCreated();
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'firmado']))->assertJsonPath('reviews.total', 1);
        $this->as('teacher')->getJson(route('api.v1.signature.show', $revision))->assertJsonPath('state', 'firmado')->assertJsonCount(3, 'signatures');
    }

    public function test_con_otro_motivo_biblioteca_escribe_el_texto_y_se_une_a_los_motivos_marcados(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision))->assertSuccessful();
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.comment_reasons.5', ['code' => 'otro', 'label' => 'Otro motivo']);

        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), ['reasons' => ['otro']])
            ->assertJsonValidationErrors(['other' => 'Escriba el otro motivo.']);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto', 'otro'], 'other' => '  Falta la 3.ª edición de Pressman. ',
        ])->assertCreated();

        $this->assertSame('El código del libro es incorrecto; Falta la 3.ª edición de Pressman.', $revision->fresh()->correccion_justificacion);
        $this->assertSame('correccion_solicitada', $syllabus->fresh()->estado);
    }

    public function test_con_la_convocatoria_cerrada_biblioteca_no_devuelve_ni_aprueba(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $syllabus->convocation->update(['estado' => Convocation::STATE_CLOSED]);
        $message = 'La convocatoria está cerrada: la revisión queda solo para consulta y no admite decisiones.';

        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('review.can_comment', false)
            ->assertJsonPath('review.can_verify', false);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('message', $message);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision))->assertForbidden();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertNull($revision->fresh()->correccion_etapa);
    }

    /**
     * @return array{Syllabus, SyllabusRevision}
     */
    private function submittedRevision(): array
    {
        $this->as('admin')->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-165']);
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
                ['version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->valueFor($field)],
            )->assertOk();
        }
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revision = SyllabusRevision::query()->firstOrFail();

        return [$syllabus->fresh(), $revision->fresh()];
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
        return $this->answeredValue($field, "Contenido {$field->clave}");
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
