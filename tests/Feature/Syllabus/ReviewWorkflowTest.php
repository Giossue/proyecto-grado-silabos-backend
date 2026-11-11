<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Application\RevisionDiff;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use LogicException;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use AssertsInternalNotifications;
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use SignsStatements;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    private User $teacher;

    private RoleAssignment $teacherContext;

    private User $librarian;

    private RoleAssignment $librarianContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);

        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
        $this->librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $this->librarianContext = $this->librarian->roleAssignments()->firstOrFail();
    }

    public function test_changing_the_faculty_returns_a_sent_syllabus_to_draft_after_confirming(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $faculty = $syllabus->career->faculty;
        $written = $syllabus->values()->get()->mapWithKeys(fn ($value): array => [$value->definicion_campo_id => $value->valor])->all();
        $payload = [
            'nombre' => 'Facultad con otro nombre',
            'campus' => $faculty->campus->value,
            'logo' => $this->transparentPng(300, 100),
        ];

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$syllabus->convocation, 'pausar']))->assertSuccessful();
        $update = fn (array $extra = []) => $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['entity' => 'facultad', 'record' => $faculty]), [...$payload, ...$extra]);

        $update()->assertUnprocessable()->assertJsonValidationErrors('purge_required')->assertJsonPath('errors.purge_count.0', '1');
        $this->assertNotSame('Facultad con otro nombre', $faculty->fresh()->nombre);
        $this->assertSame('en_revision', $syllabus->fresh()->estado);

        $update(['confirm_purge' => true])->assertSuccessful();

        $this->assertSame('Facultad con otro nombre', $faculty->fresh()->nombre);
        $fresh = $syllabus->fresh();
        $this->assertSame('borrador', $fresh->estado);
        $this->assertSame('Facultad con otro nombre', data_get($fresh->contexto_academico, 'career.faculty'));
        $this->assertEquals($written, $fresh->values()->get()->mapWithKeys(fn ($value): array => [$value->definicion_campo_id => $value->valor])->all());
        $this->assertDatabaseMissing('revisiones_silabo', ['revision_silabo_id' => $revision->id]);
        $this->assertTrue(InternalNotification::query()->where('tipo', 'silabo.devuelto_borrador')->exists());
        $this->assertSame($faculty->fresh()->logoObject->huella_sha256, data_get($fresh->contexto_academico, 'career.faculty_logo'));
    }

    public function test_changing_the_career_name_returns_a_sent_syllabus_to_draft_after_confirming(): void
    {
        [$syllabus] = $this->submitValidDraft();
        $career = $syllabus->career;
        $payload = ['faculty_id' => $career->facultad_id, 'modality' => $career->modalidad->value, 'code' => $career->codigo_carrera, 'nombre' => 'Carrera con otro nombre'];
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$syllabus->convocation, 'pausar']))->assertSuccessful();
        $update = fn (array $extra = []) => $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['entity' => 'carrera', 'record' => $career]), [...$payload, ...$extra]);

        $update()->assertUnprocessable()->assertJsonPath('errors.purge_count.0', '1');
        $this->assertSame('en_revision', $syllabus->fresh()->estado);

        $update(['confirm_purge' => true])->assertSuccessful();

        $fresh = $syllabus->fresh();
        $this->assertSame('borrador', $fresh->estado);
        $this->assertSame('Carrera con otro nombre', data_get($fresh->contexto_academico, 'career.name'));
    }

    public function test_documents_and_variables_are_frozen_with_the_submitted_revision(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $snapshot = $revision->fotografia;
        $fingerprint = $revision->huella_sha256;
        $this->assertSame('Arquitectura de Software', $snapshot['template_variables']['nombre_asignatura']);
        $field = SyllabusTemplate::institutional()->fields()->where('clave', 'asignatura')->firstOrFail();
        $syllabus->convocation->process->update(['estado' => 'cerrado']);
        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.template.structure.fields.update', [SyllabusTemplate::institutional(), $field]), [
            'content' => FreeText::fromText('Texto institucional futuro'),
        ])->assertOk();
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $this->assertSame($fingerprint, $revision->fresh()->huella_sha256);
        $payload = $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();
        $this->assertEquals($snapshot['sections'][0]['fields'][0]['value'], $payload->json('syllabus.sections.0.fields.0.document'));
    }

    public function test_custom_design_change_never_purges_an_approved_syllabus(): void
    {
        $syllabus = $this->createValidDraft();
        $field = SyllabusTemplate::institutional()->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $url = route('api.v1.admin.template.structure.fields.update', [SyllabusTemplate::institutional(), $field]);
        $payload = ['label' => 'Objetivo general (ajustado)'];
        $this->actingAsAdministrator()->patchJson($url, $payload)->assertJsonValidationErrors('process');
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revisionId = SyllabusRevision::query()->firstOrFail()->id;
        $this->approveThroughChain(SyllabusRevision::query()->findOrFail($revisionId));
        $approvalId = Approval::query()->firstOrFail()->id;
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$syllabus->convocation->process, 'pausar']))->assertSuccessful();
        $this->patchJson($url, $payload)->assertSuccessful();
        $this->assertSame('Objetivo general (ajustado)', $field->fresh()->etiqueta);
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'aprobado']);
        $this->assertDatabaseHas('revisiones_silabo', ['revision_silabo_id' => $revisionId]);
        $this->assertDatabaseHas('aprobaciones', ['aprobacion_id' => $approvalId]);
        $this->assertDatabaseCount('silabos', 1);
    }

    public function test_cp_f_revision_submission_is_validated_idempotent_and_immutable(): void
    {
        $syllabus = $this->createValidDraft();
        $lockVersion = $syllabus->version_bloqueo;
        $key = (string) Str::uuid();

        $this->actingAsTeacher()
            ->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('syllabus.version_bloqueo', $lockVersion));
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $lockVersion,
            'idempotency_key' => $key,
        ])->assertSuccessful();

        $revision = SyllabusRevision::query()->firstOrFail();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertSame(1, $revision->numero_revision);
        $this->assertSame(SyllabusTemplate::institutional()->id, $revision->fotografia['template_id']);
        $this->assertSame($syllabus->contexto_academico, $revision->fotografia['academic_context']);
        $this->assertNotEmpty($revision->fotografia['sections']);
        $this->assertSame(app(CanonicalHasher::class)->hash($revision->fotografia), $revision->huella_sha256);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'silabo.enviado',
            'recurso_id' => $revision->id,
            'detalles->from_state' => 'borrador',
            'detalles->to_state' => 'en_revision',
        ]);
        // Coordinación ya no recibe aviso por cada envío: lo ve en Revisión y en el resumen diario.
        $this->assertNoWorkflowNotification($syllabus->id, 'silabo.enviado');

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $lockVersion,
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $this->assertDatabaseCount('revisiones_silabo', 1);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('syllabus');
        $this->assertDatabaseCount('revisiones_silabo', 1);

        $originalFingerprint = $revision->huella_sha256;
        try {
            $revision->update(['huella_sha256' => str_repeat('0', 64)]);
            $this->fail('El modelo permitió modificar una revisión histórica.');
        } catch (LogicException) {
            $this->assertSame($originalFingerprint, $revision->fresh()->huella_sha256);
        }

        try {
            DB::transaction(fn () => DB::table('revisiones_silabo')
                ->where('revision_silabo_id', $revision->id)
                ->update(['hash_contenido' => str_repeat('0', 64)]));
            $this->fail('PostgreSQL permitió modificar una revisión histórica.');
        } catch (QueryException) {
            $this->assertSame($originalFingerprint, $revision->fresh()->huella_sha256);
        }
    }

    public function test_cp_f_blocking_validation_prevents_submission_without_partial_state(): void
    {
        $syllabus = $this->createStartedDraft();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('validation');

        $this->assertSame('borrador', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('revisiones_silabo', 0);
        $this->assertDatabaseMissing('eventos_auditoria', ['accion' => 'silabo.enviado']);
    }

    public function test_cp_f_stale_submission_returns_to_confirmation_without_creating_evidence(): void
    {
        $syllabus = $this->createValidDraft();
        $staleVersion = $syllabus->version_bloqueo;
        $syllabus->increment('version_borrador');

        $this->actingAsTeacher()
            ->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
                'signature' => $this->teacherSignature($syllabus, $this->teacher),
                'version_bloqueo' => $staleVersion,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'conflicto_version_borrador');

        $this->assertSame('borrador', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('revisiones_silabo', 0);
    }

    public function test_review_snapshot_carries_the_official_identification_document(): void
    {
        [, $revision] = $this->submitValidDraft();

        $fields = collect($this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))->assertOk()->json('revision.snapshot.sections'))
            ->flatMap(fn ($section) => $section['fields']);
        $automatic = $fields->firstWhere('key', 'asignatura');
        $this->assertSame('table', $automatic['document']['content'][0]['type']);
        $this->assertSame('doc', $fields->firstWhere('key', 'bibliografia')['document']['type']);
    }

    public function test_cp_f_review_workspace_enforces_role_and_record_scope(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('revision.number', 1)
                ->where('syllabus.id', $syllabus->id));
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->actingAsCoordinator()
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('presentation.title', 'PROGRAMA DE ASIGNATURA (SÍLABO)')
            ->assertJsonPath('presentation.appearance.font_family', 'Arial')
            ->assertJsonStructure([
                'presentation' => ['logos' => ['institution', 'faculty']],
                'revision' => ['snapshot' => ['sections']],
            ]);
        $this->actingAsTeacher()->getJson(route('api.v1.coordination.reviews.show', $revision))->assertForbidden();
        $this->actingAsAdministrator()->getJson(route('api.v1.coordination.reviews.show', $revision))->assertForbidden();

        $career = Career::query()->create([
            'facultad_id' => Career::query()->firstOrFail()->facultad_id,
            'codigo_carrera' => 'OTRA-CARRERA',
            'nombre' => 'Otra carrera',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
        $this->coordinatorContext->update(['carrera_id' => $career->id]);
        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))->assertForbidden();
    }

    public function test_cp_f_correction_preserves_first_snapshot_and_is_verified_on_the_resubmission(): void
    {
        [$syllabus, $firstRevision] = $this->submitValidDraft();
        $field = $this->editableScalarField($syllabus);
        $freeTextField = FieldDefinition::query()->where('clave', 'bibliografia')->firstOrFail();
        $firstSnapshot = $firstRevision->fotografia;

        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $firstRevision), ['signature' => $this->revisionSignature($firstRevision, 'biblioteca', $this->librarian)])->assertForbidden();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $firstRevision), [
            'justification' => 'Corto',
        ])->assertJsonValidationErrors('justification');
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $firstRevision), [
            'justification' => 'Se requiere precisión académica antes de aprobar la revisión.',
        ])->assertSuccessful();

        $syllabus->refresh();
        $this->assertSame('correccion_solicitada', $syllabus->estado);
        $this->assertWorkflowNotification($syllabus->id, 'silabo.correccion_solicitada');
        $this->actingAsCoordinator()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => 'PV-16 continúa denegada.',
        ])->assertForbidden();
        $this->actingAsTeacher()
            ->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.review_comments.0.stage', 'coordinacion')
            ->assertJsonPath('syllabus.review_comments.0.comment', 'Se requiere precisión académica antes de aprobar la revisión.')
            ->assertJsonMissingPath('syllabus.observations');

        $this->assertFalse(Route::has('api.v1.coordination.reviews.observations.store'));
        $this->assertFalse(Route::has('api.v1.teacher.syllabi.observations.respond'));
        $this->assertFalse(Route::has('api.v1.coordination.reviews.observations.verify'));

        $syllabus->refresh();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => $this->freeTextValue('Descripción corregida con evidencia académica.'),
        ])->assertOk();
        $syllabus->refresh();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $freeTextField]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => $this->answeredValue($freeTextField, 'Bibliografía básica corregida.'),
        ])->assertOk();
        $syllabus->refresh();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        $secondRevision = SyllabusRevision::query()->where('numero_revision', 2)->firstOrFail();
        $this->assertSame($firstSnapshot, $firstRevision->fresh()->fotografia);
        $this->assertNotSame($firstRevision->huella_sha256, $secondRevision->huella_sha256);
        $this->assertSame($firstRevision->id, $secondRevision->revision_anterior_id);
        $comparison = app(RevisionDiff::class)->compare($firstRevision, $secondRevision);
        $this->assertGreaterThan(0, $comparison['changed_fields']);
        $freeTextChange = collect($comparison['changes'])->firstWhere('field_key', 'bibliografia');
        $this->assertStringContainsString('Contenido académico bibliografia', FreeText::plainText($freeTextChange['before']['value']));
        $this->assertStringContainsString('Bibliografía básica corregida.', FreeText::plainText($freeTextChange['after']['value']));

        $this->actingAsTeacher()
            ->getJson(route('api.v1.coordination.reviews.compare', [$firstRevision, $secondRevision]))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('comparison.before_revision', 1)
                ->where('syllabus.current_revision_id', $secondRevision->id)
                ->where('syllabus.revisions.0.number', 1)
                ->where('syllabus.revisions.1.id', $secondRevision->id)
                ->where('comparison.after_revision', 2));
    }

    public function test_cp_f_approval_follows_the_chain_and_is_idempotent(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $key = (string) Str::uuid();

        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->librarian)])->assertForbidden();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator),
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('aprobaciones', 0);
        $this->assertDatabaseHas('revisiones_coordinacion', ['revision_silabo_id' => $revision->id, 'revisado_por' => $this->coordinator->id]);
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $revision->id]);
        $this->assertWorkflowNotification($syllabus->id, 'coordinacion.aprobado');
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator),
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $this->assertDatabaseCount('revisiones_coordinacion', 1);

        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->librarian)])->assertCreated();
        $approval = Approval::query()->firstOrFail();
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->assertSame($this->librarian->id, $approval->aprobado_por);
        $this->assertSame($revision->id, $approval->revision_silabo_id);
        $this->assertSame(64, strlen($approval->huella_sha256));
        $this->assertFalse(InternalNotification::query()->where('tipo', 'silabo.aprobado')->exists());
        Queue::assertPushed(GenerateSyllabusExportJob::class);

        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->librarian)])->assertCreated();
        $this->assertDatabaseCount('aprobaciones', 1);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $this->assertDatabaseCount('revisiones_coordinacion', 1);
    }

    public function test_la_biblioteca_abre_el_pdf_de_un_silabo_aprobado_pero_no_el_de_uno_sin_enviar_a_ella(): void
    {
        [, $revision] = $this->submitValidDraft();

        $this->actingAsLibrarian()->getJson(route('api.v1.documents.show', $revision))->assertForbidden();
        $this->approveThroughChain($revision);

        $this->actingAsLibrarian()->getJson(route('api.v1.documents.show', $revision))
            ->assertOk()->assertJsonPath('revision.id', $revision->id);
    }

    public function test_a_new_coordinator_mid_convocation_receives_what_the_previous_one_approved(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $this->approveThroughChain($revision);
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $career = Career::query()->findOrFail($syllabus->convocation->carrera_id);
        $incoming = User::query()->create(['nombre' => 'COORDINADOR ENTRANTE', 'correo_electronico' => 'entrante@silabos.test', 'contrasena' => 'Temporal-2026!', 'activo' => true]);

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.users.update', $incoming), [
            'nombre' => $incoming->nombre, 'correo_electronico' => $incoming->correo_electronico,
            'role_code' => 'coordinador', 'career_id' => $career->id, 'replace_coordinator' => true,
        ])->assertOk();

        // Ninguna firma del coordinador anterior queda en la convocatoria: vuelve a «En coordinación».
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertSame(ReviewStage::COORDINATION, ReviewStage::ofRevision($revision->fresh()));
        $this->assertDatabaseCount('aprobaciones', 0);
        $this->assertDatabaseCount('exportaciones_silabo', 0);
        $this->assertDatabaseMissing('firmas_silabo', ['revision_silabo_id' => $revision->id, 'firmado_como' => 'coordinacion']);
        $this->assertDatabaseMissing('firmas_silabo', ['revision_silabo_id' => $revision->id, 'firmado_como' => 'biblioteca']);
        $this->assertDatabaseHas('firmas_silabo', ['revision_silabo_id' => $revision->id, 'firmado_como' => 'docente']);
        $this->assertSame(1, AuditEvent::query()->where('accion', 'academico.coordinacion.reemplazada')->firstOrFail()->metadatos['returned_to_review']);
    }

    public function test_cp_f_coordinator_deletes_an_approved_syllabus_and_the_teacher_starts_from_scratch(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();

        $this->approveThroughChain($revision);
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()->assertJsonPath('reset.allowed', true)->assertJsonPath('reset.erases_history', true)->assertJsonPath('reset.has_pdf', true);
        $this->actingAsTeacher()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'El docente cambió de materia y debe empezar otra vez.'])->assertForbidden();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'no'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'El docente cambió de materia y debe empezar otra vez.'])->assertSuccessful();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseCount('revisiones_silabo', 0);
        $this->assertDatabaseCount('aprobaciones', 0);
        $fresh = Syllabus::query()->where('paralelo_id', $syllabus->paralelo_id)->firstOrFail();
        $this->assertNotSame($syllabus->id, $fresh->id);
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'silabo.eliminado', 'recurso_id' => $fresh->id]);
        $this->assertStringContainsString('El docente cambió de materia', json_encode(AuditEvent::query()->where('accion', 'silabo.eliminado')->firstOrFail()->metadatos, JSON_UNESCAPED_UNICODE));
        $this->assertWorkflowNotification($fresh->id, 'silabo.eliminado');
        $this->assertFalse(Route::has('api.v1.coordination.reviews.reopen'));
    }

    public function test_coordinator_deletes_an_approved_syllabus_with_several_revisions(): void
    {
        [$syllabus, $first] = $this->submitValidDraft();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $first), [
            'justification' => 'Se requiere precisión académica antes de aprobar la revisión.',
        ])->assertSuccessful();
        $syllabus->refresh();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $second = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->firstOrFail();
        $this->assertSame(2, $second->numero_revision);
        $this->approveThroughChain($second);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'El docente cambió de materia y debe empezar otra vez.'])->assertSuccessful();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseCount('revisiones_silabo', 0);
        $this->assertDatabaseCount('firmas_silabo', 0);
        $this->assertSame('sin_iniciar', Syllabus::query()->where('paralelo_id', $syllabus->paralelo_id)->firstOrFail()->estado);
    }

    public function test_cp_f_correction_columns_are_write_once_and_complete_and_approvals_are_immutable(): void
    {
        [, $revision] = $this->submitValidDraft();
        $this->approveThroughChain($revision);
        $approval = Approval::query()->firstOrFail();
        $approvedAt = $approval->aprobado_en;

        try {
            $approval->update(['aprobado_en' => now()->addDay()]);
            $this->fail('El modelo permitió modificar una aprobación.');
        } catch (LogicException) {
            $this->assertTrue($approvedAt->equalTo($approval->fresh()->aprobado_en));
        }
        try {
            DB::transaction(fn () => DB::table('aprobaciones')->where('aprobacion_id', $approval->id)->update(['aprobado_en' => now()->addDay()]));
            $this->fail('PostgreSQL permitió modificar una aprobación.');
        } catch (QueryException) {
            $this->assertDatabaseCount('aprobaciones', 1);
        }
        try {
            DB::transaction(fn () => DB::table('aprobaciones')->where('aprobacion_id', $approval->id)->delete());
            $this->fail('PostgreSQL permitió borrar una aprobación.');
        } catch (QueryException) {
            $this->assertDatabaseCount('aprobaciones', 1);
        }

        $this->assertFalse($revision->fresh()->hasCorrectionRequest());
        try {
            DB::transaction(fn () => DB::table('revisiones_silabo')->where('revision_silabo_id', $revision->id)->update(['correccion_etapa' => 'coordinacion']));
            $this->fail('PostgreSQL permitió una solicitud de corrección incompleta.');
        } catch (QueryException) {
            $this->assertNull($revision->fresh()->correccion_etapa);
        }
        $revision->fresh()->update([
            'correccion_justificacion' => 'Falta bibliografía.',
            'correccion_etapa' => 'coordinacion',
            'correccion_solicitada_por' => $this->coordinator->id,
            'correccion_solicitada_en' => now(),
        ]);
        $this->assertTrue($revision->fresh()->hasCorrectionRequest());
        try {
            $revision->fresh()->update(['correccion_justificacion' => 'Otra']);
            $this->fail('El modelo permitió reescribir la solicitud de corrección.');
        } catch (LogicException) {
            $this->assertSame('Falta bibliografía.', $revision->fresh()->correccion_justificacion);
        }
        try {
            DB::transaction(fn () => DB::table('revisiones_silabo')->where('revision_silabo_id', $revision->id)->update(['hash_contenido' => str_repeat('b', 64)]));
            $this->fail('PostgreSQL permitió modificar el contenido de una revisión.');
        } catch (QueryException) {
            $this->assertSame($revision->huella_sha256, $revision->fresh()->huella_sha256);
        }
    }

    public function test_nobody_decides_while_the_career_or_the_institution_is_paused_like_the_teacher(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $convocation = $syllabus->convocation;
        $approve = fn () => $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator), 'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))->assertSuccessful();
        $approve()->assertForbidden()->assertJsonPath('message', 'La convocatoria de la carrera está en pausa: las revisiones se retoman cuando se reanude.');
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), ['justification' => 'Falta alinear los resultados.'])->assertForbidden();
        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertJsonPath('decisions_allowed', false)->assertJsonPath('convocation_closed', false)->assertJsonPath('decision_block', 'La convocatoria de la carrera está en pausa: las revisiones se retoman cuando se reanude.');
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))->assertSuccessful();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$convocation->process, 'pausar']))->assertSuccessful();
        $approve()->assertForbidden()->assertJsonPath('message', 'Administración pausó la convocatoria institucional: las revisiones se retoman cuando se reanude.');
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$convocation->process, 'reanudar']))->assertSuccessful();

        $approve()->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$convocation->process, 'pausar']))->assertSuccessful();
        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->librarian)])->assertForbidden();
        $this->actingAsLibrarian()->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.can_verify', false)->assertJsonPath('review.decision_block', 'Administración pausó la convocatoria institucional: las revisiones se retoman cuando se reanude.');
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
    }

    public function test_only_the_coordinator_requests_a_correction_on_the_coordination_route(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $payload = ['justification' => 'Texto libre fuera de los motivos de biblioteca.'];

        $this->actingAsTeacher()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), $payload)->assertForbidden();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator), 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        // En la etapa de biblioteca, el bibliotecario solo usa su ruta con motivos enumerados.
        $this->actingAsLibrarian()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), $payload)->assertForbidden();

        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertFalse($revision->fresh()->hasCorrectionRequest());
    }

    public function test_a_pause_that_lands_after_authorization_still_blocks_the_decision_under_the_lock(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $pauseOnNextTransaction = function () use ($syllabus): void {
            $armed = true;
            Event::listen(TransactionBeginning::class, function () use (&$armed, $syllabus): void {
                if ($armed) {
                    $armed = false;
                    Convocation::query()->whereKey($syllabus->convocatoria_id)->update(['estado' => Convocation::STATE_PAUSED]);
                }
            });
        };
        $paused = 'La convocatoria de la carrera está en pausa: las revisiones se retoman cuando se reanude.';

        $pauseOnNextTransaction();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator), 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('errors.revision.0', $paused);
        $this->assertDatabaseCount('revisiones_coordinacion', 0);

        Convocation::query()->whereKey($syllabus->convocatoria_id)->update(['estado' => Convocation::STATE_OPEN]);
        $pauseOnNextTransaction();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), ['justification' => 'Falta alinear los resultados.'])
            ->assertUnprocessable()->assertJsonPath('errors.revision.0', $paused);
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertFalse($revision->fresh()->hasCorrectionRequest());
    }

    public function test_the_coordinator_returning_a_syllabus_notifies_the_teacher_and_the_librarys_approval_notifies_the_teacher(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();

        // Coordinación ya no recibe aviso por cada envío: lo ve en Revisión y en el resumen diario.
        $this->assertNoWorkflowNotification($syllabus->id, 'silabo.enviado');
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), [
            'justification' => 'Falta alinear los resultados de aprendizaje.',
        ])->assertSuccessful();

        $this->assertWorkflowNotification($syllabus->id, 'silabo.correccion_solicitada');
    }

    public function test_cp_f_database_rejects_invalid_state_and_cross_syllabus_evidence(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $otherParallel = Parallel::query()->create([
            'asignatura_id' => $syllabus->parallel->asignatura_id,
            'codigo' => 'Z',
            'jornada' => 'matutina',
        ]);
        $otherSyllabus = Syllabus::query()->create([
            'convocatoria_id' => $syllabus->convocatoria_id,
            'paralelo_id' => $otherParallel->id,
            'estado' => 'en_revision',
            'version_bloqueo' => 1,
            'iniciado_en' => now(),
            'guardado_en' => now(),
        ]);
        $otherRevision = SyllabusRevision::query()->create([
            'silabo_id' => $otherSyllabus->id,
            'numero_revision' => 1,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 1,
            'fotografia' => $revision->fotografia,
            'huella_sha256' => app(CanonicalHasher::class)->hash($revision->fotografia),
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now(),
        ]);

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.reviews.compare', [$revision, $otherRevision]))
            ->assertJsonValidationErrors('revisions');

        try {
            DB::transaction(fn () => DB::table('silabos')->where('silabo_id', $syllabus->id)->update(['estado' => 'publicado']));
            $this->fail('PostgreSQL permitió un estado de sílabo inválido.');
        } catch (QueryException) {
            $this->assertSame('en_revision', $syllabus->fresh()->estado);
        }

        $key = (string) Str::uuid();
        $approve = fn (SyllabusRevision $target) => Approval::query()->create([
            'revision_silabo_id' => $target->id,
            'clave_idempotencia' => $key,
            'huella_sha256' => str_repeat('a', 64),
            'aprobado_por' => $this->coordinator->id,
            'aprobado_en' => now(),
        ]);
        $approve($revision);
        $approve($otherRevision);
        $nextRevision = SyllabusRevision::query()->create([
            'silabo_id' => $syllabus->id,
            'revision_anterior_id' => $revision->id,
            'numero_revision' => 2,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 2,
            'fotografia' => $revision->fotografia,
            'huella_sha256' => $revision->huella_sha256,
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now(),
        ]);
        try {
            DB::transaction(fn () => $approve($nextRevision));
            $this->fail('PostgreSQL permitió repetir la clave de idempotencia de una aprobación del sílabo.');
        } catch (QueryException) {
            $this->assertDatabaseCount('aprobaciones', 2);
        }
    }

    /**
     * @return array{Syllabus, SyllabusRevision}
     */
    private function submitValidDraft(): array
    {
        $syllabus = $this->createValidDraft();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        return [$syllabus->fresh(), SyllabusRevision::query()->latest('enviado_en')->firstOrFail()];
    }

    private function createValidDraft(): Syllabus
    {
        $syllabus = $this->createStartedDraft();
        $fields = $this->teacherFields();
        foreach ($fields as $field) {
            $this->actingAsTeacher()->patchJson(
                route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
                $this->validFieldPayload($field, $syllabus->fresh()->version_bloqueo),
            )->assertOk();
        }

        return $syllabus->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function validFieldPayload(FieldDefinition $field, int $lockVersion): array
    {
        return ['version_bloqueo' => $lockVersion, 'value' => $this->answeredValue($field, "Contenido académico {$field->clave}")];
    }

    public function test_an_approved_syllabus_is_not_restarted_while_the_convocation_is_paused(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $this->approveThroughChain($revision);
        $convocation = $syllabus->convocation;
        $reset = fn () => $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'El docente cambió de materia y debe empezar otra vez.']);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))->assertSuccessful();
        $reset()->assertForbidden()->assertJsonPath('message', 'La convocatoria de la carrera está en pausa: las revisiones se retoman cuando se reanude.');
        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))->assertJsonPath('reset.allowed', false);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))->assertSuccessful();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$convocation->process, 'pausar']))->assertSuccessful();
        $reset()->assertForbidden()->assertJsonPath('message', 'Administración pausó la convocatoria institucional: las revisiones se retoman cuando se reanude.');
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'aprobado']);
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$convocation->process, 'reanudar']))->assertSuccessful();

        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))->assertJsonPath('reset.allowed', true);
        $reset()->assertSuccessful();
    }

    public function test_only_an_approved_syllabus_can_be_restarted(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $this->assertSame('en_revision', $syllabus->estado);

        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()->assertJsonPath('reset.allowed', false);
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'Se cambió la ficha y hay que volver a empezar.'])
            ->assertForbidden();
        $this->actingAsTeacher()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'Motivo de prueba.'])->assertForbidden();

        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseHas('revisiones_silabo', ['revision_silabo_id' => $revision->id]);
        $this->assertDatabaseMissing('eventos_auditoria', ['accion' => 'silabo.reiniciado', 'recurso_id' => $syllabus->id]);
    }

    public function test_closed_convocation_keeps_the_review_read_only(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $message = 'La convocatoria está cerrada: la revisión queda solo para consulta y no admite decisiones.';

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$syllabus->convocation, 'pausar']))->assertSuccessful();

        $syllabus->convocation->update(['estado' => Convocation::STATE_CLOSED]);

        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('decisions_allowed', false)
            ->assertJsonPath('convocation_closed', true)
            ->assertJsonPath('reset.allowed', false);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), [
            'justification' => 'Se requiere precisión académica antes de aprobar la revisión.',
        ])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'Motivo de prueba.'])->assertForbidden();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus), ['reason' => 'El docente cambió de materia y debe empezar otra vez.'])->assertForbidden();

        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('aprobaciones', 0);
    }

    public function test_pdf_template_migration_preserves_approval_and_restarts_unapproved_evidence(): void
    {
        [$approved, $revision] = $this->submitValidDraft();
        $this->approveThroughChain($revision);
        $snapshot = $revision->fotografia;
        $fingerprint = $revision->huella_sha256;
        $parallel = $approved->parallel->replicate();
        $parallel->codigo = 'B';
        $parallel->save();
        TeacherAssignment::query()->create([
            'asignacion_rol_id' => $this->teacherContext->id, 'paralelo_id' => $parallel->id, 'activo' => true,
        ]);
        app(GenerateConvocationSyllabi::class)->generateForParallel($approved->convocation, $parallel);
        $pending = Syllabus::query()->where('paralelo_id', $parallel->id)->firstOrFail();
        $pending->update(['estado' => 'borrador', 'iniciado_en' => now()]);
        foreach ($approved->values()->get() as $value) {
            $pending->values()->where('definicion_campo_id', $value->definicion_campo_id)->update(['valor' => $value->valor]);
        }
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $pending), [
            'signature' => $this->teacherSignature($pending, $this->teacher),
            'version_bloqueo' => 0, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $pendingRevision = $pending->revisions()->firstOrFail()->id;
        $this->restorePreviousFieldColumns();
        $this->replayFieldDocumentsMigration();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $approved->id, 'estado_silabo' => 'aprobado']);
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $this->assertSame($fingerprint, $revision->fresh()->huella_sha256);
        $this->assertDatabaseHas('aprobaciones', ['revision_silabo_id' => $revision->id]);
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $pending->id]);
        $this->assertDatabaseMissing('revisiones_silabo', ['revision_silabo_id' => $pendingRevision]);
        $replacement = Syllabus::query()->where('paralelo_id', $parallel->id)->firstOrFail();
        $this->assertSame('sin_iniciar', $replacement->estado);
        $this->assertSame(0, $replacement->version_bloqueo);
        $this->assertCount(12, SyllabusTemplate::institutional()->sections);
        $this->assertFalse(Schema::hasColumn('definiciones_campo', 'tipo_definicion_campo'));
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $approved))->assertOk()
            ->assertJsonPath('syllabus.sections.0.fields.0.key', $snapshot['sections'][0]['fields'][0]['key']);
        $description = SyllabusTemplate::institutional()->fields()->where('clave', 'descripcion')->firstOrFail();
        $this->assertStringContainsString('Ficha de prueba', FreeText::plainText($replacement->values()->where('definicion_campo_id', $description->id)->firstOrFail()->valor));
    }

    public function test_pdf_template_migration_preserves_a_reopened_draft_and_its_approved_evidence(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $this->approveThroughChain($revision);
        $snapshot = $revision->fotografia;
        DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
        $syllabus->update(['estado' => 'borrador']);
        DB::statement("SET LOCAL app.reinicio_institucional = 'off'");
        $field = $this->editableScalarField($syllabus);
        $this->restorePreviousFieldColumns();
        $this->replayFieldDocumentsMigration();
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $this->assertSame('borrador', $syllabus->fresh()->estado);
        $this->assertFalse($field->fresh()->getAttribute('vigente_en_plantilla'));
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->freeTextValue('Contenido conservado tras reapertura'),
        ])->assertOk();
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $detail = $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();
        $this->assertStringContainsString('Contenido conservado tras reapertura', json_encode($detail->json('syllabus.sections'), JSON_THROW_ON_ERROR));
        $this->assertSame(9, app(SyllabusSnapshot::class)->build($syllabus->fresh())['schema_version']);
    }

    private function restorePreviousFieldColumns(): void
    {
        $definitions = FieldDefinition::query()->get();
        DB::unprepared(<<<'SQL'
            ALTER TABLE campos_plantilla ADD COLUMN configuracion_campo_plantilla jsonb NOT NULL DEFAULT '{}'::jsonb;
            ALTER TABLE definiciones_campo
                ADD COLUMN clave_definicion_campo varchar(100),
                ADD COLUMN tipo_definicion_campo varchar(40) DEFAULT 'texto',
                ADD COLUMN clase_anterior_definicion_campo varchar(40),
                ADD COLUMN heredado_definicion_campo boolean DEFAULT false,
                ADD COLUMN origen_dato_definicion_campo varchar(100),
                ADD COLUMN editable_docente_definicion_campo boolean DEFAULT true,
                ADD COLUMN opciones_definicion_campo jsonb,
                ADD COLUMN condicion_definicion_campo jsonb;
            SQL);
        foreach ($definitions as $field) {
            DB::table('definiciones_campo')->where('definicion_campo_id', $field->id)->update([
                'clave_definicion_campo' => $field->clave,
                'tipo_definicion_campo' => match ($field->quien_llena) {
                    'institucional' => 'fijo', 'coordinacion' => 'ficha', default => 'texto'
                },
                'origen_dato_definicion_campo' => $field->quien_llena === 'coordinacion' ? 'ficha' : null,
                'opciones_definicion_campo' => json_encode(['content' => $field->contenido], JSON_THROW_ON_ERROR),
            ]);
        }
        DB::unprepared(<<<'SQL'
            ALTER TABLE definiciones_campo DROP CONSTRAINT definiciones_campo_responsable_check, DROP CONSTRAINT definiciones_campo_documento_check,
                DROP COLUMN quien_llena_definicion_campo, DROP COLUMN contenido_definicion_campo;
            ALTER TABLE secciones_plantilla DROP CONSTRAINT secciones_plantilla_orientacion_check, DROP COLUMN orientacion_seccion_plantilla;
            SQL);
    }

    private function replayFieldDocumentsMigration(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION responsable_anterior() RETURNS trigger AS $$
            BEGIN
                IF NEW.quien_llena_definicion_campo = 'carrera' THEN
                    NEW.quien_llena_definicion_campo := CASE WHEN (SELECT clave_campo_plantilla FROM campos_plantilla WHERE campo_plantilla_id = NEW.campo_plantilla_id)
                        IN ('descripcion', 'objetivo_general', 'objetivos_especificos', 'resultados_aprendizaje', 'habilidades_blandas', 'contribucion_perfil')
                        THEN 'coordinacion' ELSE 'docente' END;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER responsable_anterior BEFORE INSERT OR UPDATE ON definiciones_campo
                FOR EACH ROW EXECUTE FUNCTION responsable_anterior();
            SQL);
        (require database_path('migrations/2026_10_13_000219_replace_template_with_field_documents.php'))->up();
        DB::unprepared('DROP TRIGGER responsable_anterior ON definiciones_campo; DROP FUNCTION responsable_anterior();');
        (require database_path('migrations/2026_11_10_000268_career_field_replaces_teacher_and_coordination.php'))->up();
    }

    private function assertNoWorkflowNotification(string $syllabusId, string $eventType): void
    {
        $this->assertFalse(InternalNotification::query()->where('tipo', $eventType)->where('recurso_id', $syllabusId)->exists());
    }

    private function assertWorkflowNotification(string $syllabusId, string $eventType): void
    {
        $this->assertTrue(InternalNotification::query()
            ->where('tipo', $eventType)
            ->where('tipo_recurso', 'silabo')
            ->where('recurso_id', $syllabusId)
            ->exists());
    }

    private function createStartedDraft(): Syllabus
    {
        $template = $this->publishedConfiguration();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $convocation = Convocation::query()->firstOrFail();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();

        return $syllabus->fresh();
    }

    private function publishedConfiguration(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-04']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();

        return $template->fresh();
    }

    private function editableScalarField(Syllabus $syllabus): FieldDefinition
    {
        return FieldDefinition::query()
            ->where('quien_llena', 'carrera')
            ->where('clave', 'tecnicas_ensenanza')
            ->firstOrFail();
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

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

    private function actingAsLibrarian(): static
    {
        $this->actingAs($this->librarian)->withSession(['active_role_assignment_id' => $this->librarianContext->id]);

        return $this;
    }

    private function approveThroughChain(SyllabusRevision $revision): void
    {
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->coordinator),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->librarian)])->assertCreated();
    }
}
