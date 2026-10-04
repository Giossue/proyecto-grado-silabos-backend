<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Configuration\Application\Actions\SaveTemplateDocument;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Application\RevisionDiff;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use LogicException;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use AssertsInternalNotifications;
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

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
        $this->configureFacultyLogos();
        // I-173: la aprobación del decanato encola el PDF; aquí no hace falta generarlo.
        Queue::fake([GenerateSyllabusExportJob::class]);

        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
        $this->librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $this->librarianContext = $this->librarian->roleAssignments()->firstOrFail();
        $this->dean = User::query()->where('correo_electronico', 'decano@silabos.test')->firstOrFail();
        $this->deanContext = $this->dean->roleAssignments()->firstOrFail();
    }

    public function test_custom_design_and_variables_are_frozen_with_the_submitted_revision(): void
    {
        $syllabus = $this->createValidDraft();
        $block = SyllabusTemplate::institutional()->fields()->where('clave', 'asignatura')->firstOrFail()->block;
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('syllabus.template_variables.nombre_asignatura', 'Arquitectura de Software')
                ->where('syllabus.template_variables.correo_docente', 'docente@silabos.test')
                ->has('syllabus.sections.0.blocks.0.document'));
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->version_bloqueo, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revision = SyllabusRevision::query()->firstOrFail();
        $snapshot = $revision->fotografia;
        $this->assertEquals($block->configuracion['document'], $snapshot['sections'][0]['blocks'][0]['document']);
        $this->assertSame('Arquitectura de Software', $snapshot['template_variables']['nombre_asignatura']);
        $this->assertSame('docente@silabos.test', $snapshot['template_variables']['correo_docente']);
        $fingerprint = $revision->huella_sha256;
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$syllabus->convocation->process, 'cerrar']))
            ->assertSuccessful();
        $changed = TemplateDocumentDefaults::identification();
        $changed['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Cambio futuro']]];
        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.template.blocks.document.update', [SyllabusTemplate::institutional(), $block]), [
            'document' => $changed, 'fingerprint' => SaveTemplateDocument::fingerprint($block),
        ])->assertJsonValidationErrors('document');
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
        $this->assertSame($fingerprint, $revision->fresh()->huella_sha256);
        $objective = SyllabusTemplate::institutional()->fields()->where('clave', 'objetivo_general')->firstOrFail();
        $objectiveBlock = $objective->block;
        $this->patchJson(route('api.v1.admin.template.blocks.document.update', [SyllabusTemplate::institutional(), $objectiveBlock]), [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texto fijo futuro']]]]],
            'fingerprint' => SaveTemplateDocument::fingerprint($objectiveBlock),
        ])->assertSuccessful();
        $this->assertFalse($objective->fresh()->editable_docente);
        $this->assertTrue($syllabus->values()->where('definicion_campo_id', $objective->id)->exists());
        $this->assertSame($snapshot, $revision->fresh()->fotografia);
    }

    public function test_custom_design_change_never_purges_an_approved_syllabus(): void
    {
        $syllabus = $this->createValidDraft();
        $block = SyllabusTemplate::institutional()->fields()->where('clave', 'objetivo_general')->firstOrFail()->block;
        $url = route('api.v1.admin.template.blocks.document.update', [SyllabusTemplate::institutional(), $block]);
        $document = $block->configuracion['document'];
        $document['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nueva instrucción']]];
        $payload = ['document' => $document, 'fingerprint' => SaveTemplateDocument::fingerprint($block)];
        $this->actingAsAdministrator()->patch($url, $payload)->assertJsonValidationErrors('process');
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revisionId = SyllabusRevision::query()->firstOrFail()->id;
        $this->approveThroughChain(SyllabusRevision::query()->findOrFail($revisionId));
        $approvalId = Approval::query()->firstOrFail()->id;
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$syllabus->convocation->process, 'pausar']))->assertSuccessful();
        $this->patch($url, $payload)->assertSuccessful();
        $this->assertNotSame($block->configuracion['document'], $block->fresh()->configuracion['document']);
        $this->assertDatabaseHas('silabos', ['id' => $syllabus->id, 'estado_silabo' => 'aprobado']);
        $this->assertDatabaseHas('revisiones_silabo', ['id' => $revisionId]);
        $this->assertDatabaseHas('aprobaciones', ['id' => $approvalId]);
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
        $this->assertWorkflowNotification($syllabus->id, 'silabo.enviado');

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $lockVersion,
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $this->assertDatabaseCount('revisiones_silabo', 1);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
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
                ->where('id', $revision->id)
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
                'version_bloqueo' => $staleVersion,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'conflicto_version_borrador');

        $this->assertSame('borrador', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('revisiones_silabo', 0);
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

        // I-173: mientras biblioteca revisa, coordinación no decide.
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $firstRevision), [
            'justification' => 'Se requiere precisión académica antes de aprobar la revisión.',
        ])->assertUnprocessable()->assertJsonPath('errors.revision.0', 'La revisión no está en su etapa; otro revisor la tiene ahora.');
        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $firstRevision))->assertCreated();
        // I-177: un comentario general, sin observaciones por campo.
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
            'value' => 'Descripción corregida con evidencia académica.',
        ])->assertOk();
        $syllabus->refresh();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $freeTextField]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => $this->freeTextValue('Bibliografía básica corregida.'),
        ])->assertOk();
        $syllabus->refresh();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
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
        $this->assertEquals($this->freeTextValue('Contenido académico bibliografia'), $freeTextChange['before']['value']);
        $this->assertEquals($this->freeTextValue('Bibliografía básica corregida.'), $freeTextChange['after']['value']);

        $this->actingAsTeacher()
            ->getJson(route('api.v1.coordination.reviews.compare', [$firstRevision, $secondRevision]))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('comparison.before_revision', 1)
                ->where('comparison.after_revision', 2));
    }

    public function test_cp_f_approval_follows_the_chain_and_is_idempotent(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $key = (string) Str::uuid();

        // I-173: biblioteca → coordinación → decanato; el decanato fija la aprobación.
        $this->actingAsDean()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => $key,
        ])->assertJsonValidationErrors('revision');
        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $revision))->assertCreated();
        $this->assertWorkflowNotification($syllabus->id, 'biblioteca.verificado');
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseHas('revisiones_coordinacion', ['revision_silabo_id' => $revision->id, 'revisado_por' => $this->coordinator->id]);
        $this->assertWorkflowNotification($syllabus->id, 'silabo.revisado_coordinacion');
        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()->assertJsonPath('syllabus.stage', 'decanato')->assertJsonPath('can_decide', false);

        $this->actingAsDean()->getJson(route('api.v1.coordination.reviews.show', $revision))
            ->assertOk()->assertJsonPath('my_stage', 'decanato')->assertJsonPath('can_decide', true);
        $this->actingAsDean()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $approval = Approval::query()->firstOrFail();
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->assertSame($this->dean->id, $approval->aprobado_por);
        $this->assertSame($revision->id, $approval->revision_silabo_id);
        $this->assertSame(64, strlen($approval->huella_sha256));
        $this->assertWorkflowNotification($syllabus->id, 'silabo.aprobado');
        Queue::assertPushed(GenerateSyllabusExportJob::class);

        $this->actingAsDean()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $this->assertDatabaseCount('aprobaciones', 1);

        $this->actingAsDean()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('revision');
    }

    public function test_cp_f_reopening_preserves_approval_restores_snapshot_and_links_next_revision(): void
    {
        [$syllabus, $approvedRevision] = $this->submitValidDraft();
        $this->approveThroughChain($approvedRevision);
        $approval = Approval::query()->firstOrFail();
        $field = $this->editableScalarField($syllabus);
        $approvedValue = $syllabus->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor;
        $syllabus->values()->where('definicion_campo_id', $field->id)->firstOrFail()->update([
            'valor' => 'Deriva fuera del flujo que debe descartarse.',
        ]);
        $key = (string) Str::uuid();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reopen', $syllabus), [
            'idempotency_key' => $key,
            'cause' => 'Se detectó una actualización académica posterior a la aprobación.',
        ])->assertSuccessful();
        $syllabus->refresh();
        $reopened = $syllabus->reopenedApprovals()->firstOrFail();
        $this->assertSame('correccion_solicitada', $syllabus->estado);
        $this->assertSame($approvedValue, $syllabus->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor);
        $this->assertSame($approval->id, $reopened->id);
        $this->assertDatabaseCount('aprobaciones', 1);
        $this->assertDatabaseCount('revisiones_silabo', 1);
        $this->assertWorkflowNotification($syllabus->id, 'silabo.reabierto');

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reopen', $syllabus), [
            'idempotency_key' => $key,
            'cause' => 'Se detectó una actualización académica posterior a la aprobación.',
        ])->assertSuccessful();
        $this->assertSame(1, Approval::query()->whereNotNull('reabierta_en')->count());

        $this->actingAsCoordinator()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => 'Coordinación no puede editar mientras PV-16 esté pendiente.',
        ])->assertForbidden();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'value' => 'Cambio posterior a la reapertura.',
        ])->assertOk();
        $syllabus->refresh();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        $newRevision = SyllabusRevision::query()->where('numero_revision', 2)->firstOrFail();
        $this->assertSame($approvedRevision->id, $newRevision->revision_anterior_id);
        $this->assertSame($approvedRevision->id, $approval->fresh()->revision_silabo_id);
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
    }

    public function test_cp_f_reopening_and_correction_columns_are_write_once_and_complete(): void
    {
        [, $revision] = $this->submitValidDraft();
        $this->approveThroughChain($revision);
        $approval = Approval::query()->firstOrFail();
        $reopening = [
            'clave_reapertura' => (string) Str::uuid(),
            'causa_reapertura' => 'Se detectó un error.',
            'origen_reapertura' => 'coordinacion',
            'reabierta_por' => $this->coordinator->id,
            'reabierta_en' => now(),
        ];

        // Un hecho a medias no es válido en la base.
        try {
            DB::transaction(fn () => DB::table('aprobaciones')->where('id', $approval->id)->update(['causa_reapertura' => 'Solo la causa']));
            $this->fail('PostgreSQL permitió una reapertura incompleta.');
        } catch (QueryException) {
            $this->assertNull($approval->fresh()->causa_reapertura);
        }

        $approval->update($reopening);
        $this->assertTrue($approval->fresh()->isReopened());

        // Lo ya escrito no cambia y el resto de la fila sigue inmutable, en el modelo y en la base.
        foreach ([['causa_reapertura' => 'Otra causa'], ['huella_sha256' => str_repeat('b', 64)]] as $changes) {
            try {
                $approval->fresh()->update($changes);
                $this->fail('El modelo permitió modificar un registro histórico.');
            } catch (LogicException) {
                $this->assertSame('Se detectó un error.', $approval->fresh()->causa_reapertura);
            }
        }
        foreach (['causa_reapertura' => 'Otra causa', 'hash_contenido' => str_repeat('b', 64)] as $column => $value) {
            try {
                DB::transaction(fn () => DB::table('aprobaciones')->where('id', $approval->id)->update([$column => $value]));
                $this->fail('PostgreSQL permitió modificar un registro histórico.');
            } catch (QueryException) {
                $this->assertSame('Se detectó un error.', $approval->fresh()->causa_reapertura);
            }
        }
        try {
            DB::transaction(fn () => DB::table('aprobaciones')->where('id', $approval->id)->delete());
            $this->fail('PostgreSQL permitió borrar una aprobación.');
        } catch (QueryException) {
            $this->assertDatabaseCount('aprobaciones', 1);
        }

        // La misma regla para la solicitud de corrección de la revisión.
        $this->assertFalse($revision->fresh()->hasCorrectionRequest());
        try {
            DB::transaction(fn () => DB::table('revisiones_silabo')->where('id', $revision->id)->update(['correccion_etapa' => 'coordinacion']));
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
    }

    public function test_cp_f_database_rejects_invalid_state_and_cross_syllabus_evidence(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $otherParallel = Parallel::query()->create([
            'programacion_asignatura_id' => $syllabus->parallel->programacion_asignatura_id,
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
            DB::transaction(fn () => DB::table('silabos')->where('id', $syllabus->id)->update(['estado' => 'publicado']));
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

    /** @return array{Syllabus, SyllabusRevision} */
    private function submitValidDraft(): array
    {
        $syllabus = $this->createValidDraft();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        return [$syllabus->fresh(), SyllabusRevision::query()->latest('enviado_en')->firstOrFail()];
    }

    private function createValidDraft(): Syllabus
    {
        $syllabus = $this->createStartedDraft();
        $fields = FieldDefinition::query()
            ->where('heredado', false)
            ->get();
        foreach ($fields as $field) {
            $this->actingAsTeacher()->patchJson(
                route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
                $this->validFieldPayload($field, $syllabus->fresh()->version_bloqueo),
            )->assertOk();
        }

        return $syllabus->fresh();
    }

    /** @return array<string, mixed> */
    private function validFieldPayload(FieldDefinition $field, int $lockVersion): array
    {
        $optionValues = collect($field->opciones ?? [])->map(function (mixed $option): string {
            if (is_array($option)) {
                return (string) ($option['value'] ?? 'opcion');
            }

            return (string) $option;
        })->filter()->values();

        return match (true) {
            $field->tipo === 'texto_libre' => ['version_bloqueo' => $lockVersion, 'value' => $this->freeTextValue("Contenido académico {$field->clave}")],
            $field->tipo === 'booleano' => ['version_bloqueo' => $lockVersion, 'value' => true],
            $field->tipo === 'numero' => ['version_bloqueo' => $lockVersion, 'value' => 1],
            $field->tipo === 'fecha' => ['version_bloqueo' => $lockVersion, 'value' => now()->toDateString()],
            $field->tipo === 'seleccion_unica' => ['version_bloqueo' => $lockVersion, 'value' => $optionValues->first()],
            $field->tipo === 'seleccion_multiple' => ['version_bloqueo' => $lockVersion, 'value' => [$optionValues->first()]],
            default => ['version_bloqueo' => $lockVersion, 'value' => "Contenido académico {$field->clave}"],
        };
    }

    public function test_coordinator_resets_a_submitted_syllabus_so_the_teacher_starts_over(): void
    {
        [$syllabus, $revision] = $this->submitValidDraft();
        $this->assertSame('en_revision', $syllabus->estado);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.reviews.reset', $syllabus))
            ->assertSuccessful()
            ->assertSuccessful();

        $fresh = $syllabus->fresh();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertSame(0, $fresh->version_bloqueo);
        $this->assertSame(0, $fresh->values()->whereHas('field', fn ($field) => $field->where('heredado', false))->count());
        $this->assertDatabaseHas('revisiones_silabo', ['id' => $revision->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'silabo.reiniciado', 'recurso_id' => $syllabus->id]);
        $this->assertWorkflowNotification($syllabus->id, 'silabo.reiniciado');

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $this->assertSame('borrador', $syllabus->fresh()->estado);

        $this->actingAsTeacher()->postJson(route('api.v1.coordination.reviews.reset', $syllabus))->assertForbidden();
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
            ->assertJsonPath('reset.allowed', false);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), [
            'justification' => 'Se requiere precisión académica antes de aprobar la revisión.',
        ])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reset', $syllabus))->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.reopen', $syllabus), [
            'cause' => 'Intento de reabrir con la convocatoria cerrada.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('message', $message);

        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('aprobaciones', 0);
    }

    private function assertWorkflowNotification(string $syllabusId, string $eventType): void
    {
        $this->assertTrue(JobExecution::query()
            ->where('tipo', 'notificacion.interna')
            ->where('tipo_recurso', 'silabo')
            ->where('recurso_id', $syllabusId)
            ->whereJsonContains('datos_entrada', ['tipo_evento' => $eventType])
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

        return $template->fresh();
    }

    private function editableScalarField(Syllabus $syllabus): FieldDefinition
    {
        return FieldDefinition::query()
            ->where('heredado', false)
            ->where('editable_docente', true)
            ->where('clave', 'formacion_experiencia')
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

    private function actingAsDean(): static
    {
        $this->actingAs($this->dean)->withSession(['active_role_assignment_id' => $this->deanContext->id]);

        return $this;
    }

    /** Recorre la cadena I-173: biblioteca aprueba, coordinación revisa y el decanato aprueba. */
    private function approveThroughChain(SyllabusRevision $revision): void
    {
        $this->actingAsLibrarian()->postJson(route('api.v1.library.reviews.verify', $revision))->assertCreated();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $this->actingAsDean()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
    }
}
