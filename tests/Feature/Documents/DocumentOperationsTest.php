<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Application\DocumentBundleValidator;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentBundle;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use LogicException;
use RuntimeException;
use Tests\Support\BuildsTypedSnapshot;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;
use ZipArchive;

class DocumentOperationsTest extends TestCase
{
    use BuildsTypedSnapshot;
    use CreatesSyllabusProcess;
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    private User $teacher;

    private RoleAssignment $teacherContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('private');

        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
    }

    public function test_cp_f_only_authorized_users_request_an_approved_export_idempotently(): void
    {
        Queue::fake();
        [$syllabus, $revision] = $this->approvedRevision();
        $key = (string) Str::uuid();

        $this->actingAsTeacher()

            ->getJson(route('api.v1.documents.show', $revision))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('revision.number', 1)
                ->where('artifacts', []));
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $revision), [
            'idempotency_key' => $key,
        ])->assertSuccessful();
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $revision), [
            'idempotency_key' => $key,
        ])->assertSuccessful();

        $this->assertDatabaseCount('exportaciones_silabo', 1);
        Queue::assertPushed(GenerateSyllabusExportJob::class, 1);
        $artifact = ExportArtifact::query()->firstOrFail();
        $this->assertSame($revision->id, $artifact->revision_silabo_id);

        $outsider = User::factory()->create();
        $outsiderContext = RoleAssignment::query()->create([
            'usuario_id' => $outsider->id,
            'rol' => 'docente',
            'carrera_id' => $this->teacherContext->carrera_id,
            'activo' => true,
        ]);
        $this->actingAs($outsider)
            ->withSession(['active_role_assignment_id' => $outsiderContext->id])

            ->getJson(route('api.v1.documents.show', $revision))
            ->assertForbidden();
        $this->actingAsAdministrator()->getJson(route('api.v1.documents.show', $revision))->assertForbidden();

        $unapproved = SyllabusRevision::query()->create([
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
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $unapproved), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('revision');
        $this->assertDatabaseCount('exportaciones_silabo', 1);
    }

    public function test_the_word_signs_coordination_with_the_coordinator_even_when_the_library_approves_a_resubmission(): void
    {
        Queue::fake();
        [$syllabus, $first] = $this->approvedRevision();
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        CoordinationReview::query()->create([
            'revision_silabo_id' => $first->id,
            'revisado_por' => $this->coordinator->id,
            'revisado_en' => now()->subDays(3),
        ]);
        $resubmitted = SyllabusRevision::query()->create([
            'silabo_id' => $syllabus->id,
            'revision_anterior_id' => $first->id,
            'numero_revision' => 2,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 2,
            'fotografia' => $first->fotografia,
            'huella_sha256' => $first->huella_sha256,
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now()->subMinute(),
        ]);
        Approval::query()->create([
            'revision_silabo_id' => $resubmitted->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => hash('sha256', $resubmitted->huella_sha256),
            'aprobado_por' => $librarian->id,
            'aprobado_en' => now(),
        ]);
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $resubmitted), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $artifact = ExportArtifact::query()->where('revision_silabo_id', $resubmitted->id)->firstOrFail();

        $documentXml = $this->exportedDocumentXml($artifact);
        $this->assertStringContainsString($this->coordinator->nombre, $documentXml);
        $this->assertStringContainsString(now()->subDays(3)->setTimezone('America/Guayaquil')->format('d-m-Y'), $documentXml);
        // La columna de biblioteca lleva a quien verificó la bibliografía y la fecha de su aprobación.
        $this->assertStringContainsString($librarian->nombre, $documentXml);
        $this->assertStringContainsString('Fecha de verificación: ', $documentXml);
        $this->assertStringNotContainsString('en la firma electrónica', $documentXml);
    }

    public function test_cp_f_job_generates_consistent_private_documents_and_reauthorizes_download(): void
    {
        Queue::fake();
        [$syllabus, $revision] = $this->approvedRevision();
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $artifact = ExportArtifact::query()->firstOrFail();
        $job = new GenerateSyllabusExportJob($artifact->id);
        $docxBytes = $this->exportedDocx($artifact);

        $artifact->refresh()->load('pdfObject');
        $this->assertSame('completado', $artifact->estado);
        $this->assertDatabaseCount('archivos', 1);
        $pdf = $artifact->pdfObject;
        $this->assertInstanceOf(StoredObject::class, $pdf);
        Storage::disk('private')->assertExists($pdf->ruta_interna);
        $pdfBytes = Storage::disk('private')->get($pdf->ruta_interna);
        $this->assertSame(hash('sha256', $pdfBytes), $pdf->huella_sha256);
        $this->assertStringStartsWith('%PDF-1.4', $pdfBytes);
        $this->assertStringEndsWith("%%EOF\n", $pdfBytes);
        $this->assertStringNotContainsString($revision->huella_sha256, $pdfBytes);
        $this->assertStringNotContainsString('Generado:', $pdfBytes);
        $this->assertStringContainsString('Contenido academico verificable', $pdfBytes);

        $path = (string) tempnam(sys_get_temp_dir(), 'silabos-docx-');
        file_put_contents($path, $docxBytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/styles.xml'] as $entry) {
            $this->assertNotFalse($zip->locateName($entry));
        }
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);
        $this->assertIsString($documentXml);
        $this->assertStringNotContainsString(substr($revision->huella_sha256, 0, 16), $documentXml);
        $this->assertStringNotContainsString('Página', $documentXml);
        $this->assertStringContainsString('Contenido academico verificable', $documentXml);
        $this->assertStringContainsString($this->coordinator->nombre, $documentXml);
        $this->assertStringContainsString(now()->setTimezone('America/Guayaquil')->format('d-m-Y'), $documentXml);

        $input = new DocumentRenderInput(
            subject: $syllabus->subject->nombre,
            subjectCode: $syllabus->subject->codigo_asignatura,
            academicPeriod: $syllabus->convocation->process->academicPeriod()->valueOrFail('codigo'),
            revisionNumber: $revision->numero_revision,
            revisionFingerprint: $revision->huella_sha256,
            generatedAt: $artifact->solicitado_en->toIso8601String(),
            locale: 'es-EC',
            snapshot: $revision->fotografia,
        );
        $firstBundle = app(DocumentRenderer::class)->render($input);
        $secondBundle = app(DocumentRenderer::class)->render($input);
        $this->assertSame($firstBundle->docx->fingerprint(), $secondBundle->docx->fingerprint());
        $this->assertSame($firstBundle->pdf->fingerprint(), $secondBundle->pdf->fingerprint());

        $job->handle(
            app(DocumentRenderer::class),
            app(RecordAuditEvent::class),
            app(DocumentBundleValidator::class),
        );
        $this->assertDatabaseCount('archivos', 1);
        $this->assertSame($pdfBytes, Storage::disk('private')->get($pdf->ruta_interna));
        $this->assertDatabaseHas('notificaciones', [
            'usuario_id' => $this->teacher->id,
            'tipo_notificacion' => 'silabo.aprobado',
            'titulo_notificacion' => 'Sílabo aprobado',
            'tipo_recurso' => 'artefacto_exportacion',
        ]);

        $this->actingAsTeacher()->get("/api/v1/exports/{$artifact->id}/docx")->assertNotFound();
        $this->assertSame($pdfBytes, $this->actingAsTeacher()->get(route('api.v1.documents.download', $artifact))->assertOk()->streamedContent());
        $this->actingAsCoordinator()->get(route('api.v1.documents.download', $artifact))->assertOk();
        $this->actingAsAdministrator()->getJson(route('api.v1.documents.download', $artifact))->assertForbidden();

        try {
            $artifact->update(['estado' => 'fallido']);
            $this->fail('El modelo permitió modificar un artefacto completado.');
        } catch (LogicException) {
            $this->assertSame('completado', $artifact->fresh()->estado);
        }
        try {
            DB::transaction(fn () => DB::table('archivos')
                ->where('archivo_id', $pdf->id)->update(['nombre_archivo' => 'alterado.pdf']));
            $this->fail('PostgreSQL permitió reescribir un objeto publicado.');
        } catch (QueryException) {
            $this->assertSame($pdf->nombre_logico, $pdf->fresh()->nombre_logico);
        }
    }

    public function test_the_export_job_paints_a_v7_revision_with_the_four_field_types(): void
    {
        Queue::fake();
        [, $revision] = $this->approvedRevision($this->typedSnapshot());
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $revision), ['idempotency_key' => (string) Str::uuid()]);
        $artifact = ExportArtifact::query()->firstOrFail();
        $xml = $this->exportedDocumentXml($artifact);
        $this->assertSame('completado', $artifact->refresh()->estado);
        foreach (['Facultad de Pruebas Integradas', 'Texto escrito por el docente', 'Ficha heredada de la asignatura', 'Semana dos', 'Agentes'] as $text) {
            $this->assertStringContainsString($text, $xml);
        }
    }

    public function test_cp_f_failed_export_has_a_safe_cause(): void
    {
        Queue::fake();
        [, $revision] = $this->approvedRevision();
        $this->actingAsTeacher()->postJson(route('api.v1.documents.store', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $artifact = ExportArtifact::query()->firstOrFail();
        $artifact->update(['estado' => 'en_ejecucion']);
        $secret = 'SECRET=/srv/private/documento.docx';
        (new GenerateSyllabusExportJob($artifact->id))->failed(new RuntimeException($secret));

        $this->assertSame('fallido', $artifact->fresh()->estado);
        $this->actingAsTeacher()->getJson(route('api.v1.documents.show', $revision))
            ->assertJsonPath('artifacts.0.error', 'No se pudo generar el PDF. Puede reintentar.');
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'documento.exportacion_fallida',
            'resultado' => 'fallido',
        ]);
        $notice = InternalNotification::query()->where('tipo', 'documento.exportacion_fallida')->sole();
        $this->assertSame($this->administrator->id, $notice->usuario_id);
        $this->assertSame('No se pudieron generar los documentos', $notice->titulo);
        $this->assertStringNotContainsString($secret, $notice->mensaje);
        $this->assertSame($revision->syllabus->convocation->process->periodo_academico_id, $notice->periodo_academico_id);
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.notifications.open', $notice))
            ->assertOk()
            ->assertJsonPath('path', '/admin/auditoria?action=documento.exportacion_fallida');
    }

    public function test_cp_f_workflow_notification_is_stored_once_and_enforces_ownership(): void
    {
        Queue::fake();
        Mail::fake();
        [$syllabus] = $this->approvedRevision();
        $recorder = app(ScheduleInternalNotification::class);
        $key = "syllabus.approved:{$syllabus->id}";
        $period = $syllabus->convocation->process->periodo_academico_id;

        $recorder->execute('aviso.prueba', $key, [$this->coordinator->id], 'Sílabo listo para revisión', 'Mensaje de prueba.', 'silabo', $syllabus->id, $period);
        $recorder->execute('aviso.prueba', $key, [$this->coordinator->id], 'Sílabo listo para revisión', 'Mensaje de prueba.', 'silabo', $syllabus->id, $period);

        $this->assertDatabaseCount('notificaciones', 1);
        $notification = InternalNotification::query()->firstOrFail();
        $this->assertSame($this->coordinator->id, $notification->usuario_id);
        $this->actingAsCoordinator()

            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('notifications.total', 1)
                ->where('notifications.data.0.has_destination', true)
                ->where('notifications.data.0.context', 'Coordinación · '.$syllabus->convocation->career->nombre));
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.notifications.open', $notification))
            ->assertOk()
            ->assertJsonPath('path', "/coordinacion/revisiones/{$syllabus->revisions()->latest('numero_revision')->value('id')}")
            ->assertJsonPath('role_changed', false);
        $this->actingAsTeacher()->postJson(route('api.v1.notifications.read', $notification))->assertForbidden();
        $this->actingAsCoordinator()->postJson(route('api.v1.notifications.read', $notification))->assertSuccessful();
        $this->assertNotNull($notification->fresh()->leido_en);
        Mail::assertNothingSent();

        try {
            DB::transaction(fn () => DB::table('notificaciones')
                ->where('notificacion_id', $notification->id)->update(['titulo_notificacion' => 'Alterado']));
            $this->fail('PostgreSQL permitió alterar una notificación entregada.');
        } catch (QueryException) {
            $this->assertSame('Sílabo listo para revisión', $notification->fresh()->titulo);
        }
    }

    public function test_cp_f_notifications_link_export_artifacts_to_the_documents_surface(): void
    {
        [$syllabus, $revision] = $this->approvedRevision();
        $artifact = ExportArtifact::query()->create([
            'revision_silabo_id' => $revision->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'estado' => 'pendiente',
            'solicitado_por' => $this->coordinator->id,
            'solicitado_en' => now(),
        ]);
        $notification = InternalNotification::query()->create([
            'usuario_id' => $this->coordinator->id,
            'clave_deduplicacion' => "documento.exportacion.completada:{$artifact->id}",
            'tipo' => 'documento.exportacion.completada',
            'titulo' => 'Documentos listos para firmar',
            'mensaje' => 'Los documentos están disponibles.',
            'tipo_recurso' => 'artefacto_exportacion',
            'recurso_id' => $artifact->id,
            'notificado_en' => now(),
        ]);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.notifications.open', $notification))
            ->assertOk()
            ->assertJsonPath('path', "/coordinacion/revisiones/{$revision->id}/documentos");
    }

    public function test_cp_f_teacher_syllabus_notifications_use_the_canonical_teacher_path(): void
    {
        [$syllabus] = $this->approvedRevision();
        $notification = InternalNotification::query()->create([
            'usuario_id' => $this->teacher->id,
            'clave_deduplicacion' => "silabo.aprobado:{$syllabus->id}:teacher",
            'tipo' => 'silabo.aprobado',
            'titulo' => 'Sílabo aprobado',
            'mensaje' => 'El sílabo fue aprobado.',
            'tipo_recurso' => 'silabo',
            'recurso_id' => $syllabus->id,
            'notificado_en' => now(),
        ]);

        $this->actingAsTeacher()
            ->postJson(route('api.v1.notifications.open', $notification))
            ->assertOk()
            ->assertJsonPath('path', "/docente/mis-silabos/{$syllabus->id}");
    }

    public function test_opening_a_notification_before_choosing_a_scope_activates_the_matching_role(): void
    {
        [$syllabus] = $this->approvedRevision();
        $notification = InternalNotification::query()->create([
            'usuario_id' => $this->teacher->id,
            'clave_deduplicacion' => "silabo.aprobado:{$syllabus->id}:sin-ambito",
            'tipo' => 'silabo.aprobado',
            'titulo' => 'Sílabo aprobado',
            'mensaje' => 'El sílabo fue aprobado.',
            'tipo_recurso' => 'silabo',
            'recurso_id' => $syllabus->id,
            'periodo_academico_id' => $syllabus->convocation->process->periodo_academico_id,
            'notificado_en' => now(),
        ]);
        $career = $syllabus->convocation->career->nombre;
        $otherCareer = Career::query()->create([
            'facultad_id' => $syllabus->convocation->career->facultad_id,
            'codigo_carrera' => 'CARR-OTRA-DOCENCIA',
            'nombre' => 'Otra carrera con docencia',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
        RoleAssignment::query()->create([
            'usuario_id' => $this->teacher->id,
            'rol' => 'docente',
            'carrera_id' => $otherCareer->id,
            'activo' => true,
        ]);

        $this->actingAs($this->teacher)
            ->getJson(route('api.v1.session.show'))
            ->assertJsonPath('active_role', null);
        $this->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath('notifications.data.0.has_destination', true)
            ->assertJsonPath('notifications.data.0.context', "Docencia · {$career}")
            ->assertJsonMissingPath('notifications.data.0.resource_path');

        $this->actingAs($this->coordinator)
            ->postJson(route('api.v1.notifications.open', $notification))
            ->assertForbidden();

        $this->actingAs($this->teacher)
            ->postJson(route('api.v1.notifications.open', $notification))
            ->assertOk()
            ->assertJsonPath('path', "/docente/mis-silabos/{$syllabus->id}")
            ->assertJsonPath('role_changed', true);
        $this->getJson(route('api.v1.session.show'))
            ->assertJsonPath('active_role.id', $this->teacherContext->id);
        $this->assertNotNull($notification->fresh()->leido_en);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'rol_activo.seleccionado',
            'recurso_id' => $this->teacherContext->id,
        ]);
    }

    public function test_cp_f_only_administrator_reads_safe_append_only_audit(): void
    {
        $fingerprint = str_repeat('f', 64);
        $event = AuditEvent::query()->create([
            'actor_usuario_id' => $this->coordinator->id,
            'asignacion_rol_id' => $this->coordinatorContext->id,
            'accion' => 'syllabus.approved',
            'tipo_recurso' => 'approval',
            'recurso_id' => (string) Str::uuid(),
            'resultado' => 'exito',
            'metadatos' => [
                'revision_number' => 2,
                'fingerprint' => $fingerprint,
                'approval_id' => (string) Str::uuid(),
            ],
            'correlation_id' => (string) Str::uuid(),
            'ocurrido_en' => now(),
        ]);

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.audit-events.index', ['action' => 'syllabus.approved']))
            ->assertOk()
            ->assertDontSee($fingerprint)
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('filters.action', 'syllabus.approved')
                ->where('events.total', 1)
                ->where('events.data.0.details.0.label', 'Número de revisión')
                ->where('events.data.0.details.0.value', 2));
        $this->actingAsTeacher()->getJson(route('api.v1.admin.audit-events.index'))->assertForbidden();
        $this->actingAsCoordinator()->getJson(route('api.v1.admin.audit-events.index'))->assertForbidden();

        AuditEvent::query()->create([
            'actor_usuario_id' => $this->coordinator->id,
            'asignacion_rol_id' => $this->coordinatorContext->id,
            'accion' => 'academico.oferta.creacion',
            'tipo_recurso' => 'oferta',
            'recurso_id' => (string) Str::uuid(),
            'resultado' => 'exito',
            'metadatos' => [],
            'ocurrido_en' => now(),
        ]);
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.audit-events.index', ['action' => 'academico.oferta.creacion']))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('events.total', 1)
                ->where('events.data.0.action', 'Materia programada')
                ->where('events.data.0.resource', 'Programación de asignatura'));
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.audit-events.index', ['action' => 'academico.oferta.creacion', 'result' => 'exito']))
            ->assertOk()->assertJsonPath('events.total', 1);
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.audit-events.index', ['action' => 'academico.oferta.creacion', 'result' => 'fallido']))
            ->assertOk()->assertJsonPath('events.total', 0);

        try {
            $event->update(['resultado' => 'fallida']);
            $this->fail('El modelo permitió modificar auditoría histórica.');
        } catch (LogicException) {
            $this->assertSame('exito', $event->fresh()->resultado);
        }
        try {
            DB::transaction(fn () => DB::table('eventos_auditoria')
                ->where('evento_auditoria_id', $event->id)->update(['resultado' => 'fallida']));
            $this->fail('PostgreSQL permitió modificar auditoría histórica.');
        } catch (QueryException) {
            $this->assertSame('exito', $event->fresh()->resultado);
        }
    }

    public function test_audit_filters_by_period_role_and_career_without_free_text(): void
    {
        $period = AcademicPeriod::query()->create([
            'codigo' => 'ENERO-2020-JUNIO-2020',
            'nombre' => 'Enero 2020 - Junio 2020',
            'fecha_inicio' => '2020-01-06',
            'fecha_fin' => '2020-06-30',
        ]);
        $record = fn (string $when): AuditEvent => AuditEvent::query()->create([
            'actor_usuario_id' => $this->coordinator->id,
            'asignacion_rol_id' => $this->coordinatorContext->id,
            'accion' => 'academico.oferta.creacion',
            'tipo_recurso' => 'oferta',
            'recurso_id' => (string) Str::uuid(),
            'resultado' => 'exito',
            'metadatos' => [],
            'ocurrido_en' => $when,
        ]);
        $record('2020-03-10 15:00:00');
        $record(now()->toDateTimeString());
        $careerId = (string) $this->coordinatorContext->carrera_id;

        $audit = fn (array $filters) => $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.audit-events.index', $filters))->assertOk();

        $audit([])
            ->assertJsonPath('period_options.0.value', fn ($value) => is_string($value))
            ->assertJsonFragment(['value' => 'coordinador', 'label' => 'Coordinador'])
            ->assertJsonFragment(['value' => $careerId]);
        $audit(['period' => $period->id])->assertJsonPath('events.total', 1)->assertJsonPath('filters.period', $period->id);
        $audit(['role' => 'coordinador'])->assertJsonPath('events.total', 2);
        $audit(['role' => 'docente'])->assertJsonPath('events.total', 0);
        $audit(['career' => $careerId])->assertJsonPath('events.total', 2);
        $audit(['career' => (string) Str::uuid()])->assertJsonPath('events.total', 0);
        $audit(['period' => (string) Str::uuid()])->assertJsonPath('events.total', 0);
        $audit(['period' => $period->id, 'role' => 'coordinador'])->assertJsonPath('events.total', 1);
    }

    /** @return array{Syllabus, SyllabusRevision} */
    private function approvedRevision(?array $custom = null): array
    {
        $career = Career::query()->firstOrFail();
        $subject = Subject::query()->firstOrFail();
        $version = SyllabusTemplate::query()->create([
            'nombre' => 'Plantilla documental CP-F',
            'activo' => true,
        ]);
        $convocation = Convocation::query()->create([
            'carrera_id' => $career->id,
            'proceso_id' => $this->openSyllabusProcess($version->id)->id,
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
        $snapshot = $custom ?? [
            'schema_version' => 1,
            'template_id' => $version->id,
            'sections' => [[
                'key' => 'general',
                'title' => 'Información general',
                'blocks' => [[
                    'key' => 'contenido',
                    'title' => 'Contenido académico',
                    'fields' => [[
                        'key' => 'descripcion',
                        'label' => 'Descripción',
                        'type' => 'text',
                        'inherited' => false,
                        'value' => 'Contenido academico verificable',
                    ]],
                ]],
            ], [
                'key' => 'revision',
                'title' => 'Revisión y aprobación',
                'blocks' => [[
                    'key' => 'firmas',
                    'title' => 'Revisión y aprobación',
                    'content_type' => 'fixed',
                    'document' => TemplateDocumentDefaults::signatures(),
                    'fields' => [],
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
        Approval::query()->create([
            'revision_silabo_id' => $revision->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => hash('sha256', $revision->huella_sha256),
            'aprobado_por' => $this->coordinator->id,
            'aprobado_en' => now(),
        ]);

        return [$syllabus->fresh(), $revision->fresh()];
    }

    private function exportedDocx(ExportArtifact $artifact): string
    {
        $spy = new class(app(DocumentRenderer::class)) implements DocumentRenderer
        {
            public ?DocumentBundle $last = null;

            public function __construct(private readonly DocumentRenderer $inner) {}

            public function version(): string
            {
                return $this->inner->version();
            }

            public function render(DocumentRenderInput $input): DocumentBundle
            {
                return $this->last = $this->inner->render($input);
            }
        };
        (new GenerateSyllabusExportJob($artifact->id))->handle($spy, app(RecordAuditEvent::class), app(DocumentBundleValidator::class));
        $this->assertNotNull($spy->last);

        return $spy->last->docx->bytes;
    }

    private function exportedDocumentXml(ExportArtifact $artifact): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'silabos-docx-');
        file_put_contents($path, $this->exportedDocx($artifact));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)
            ->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)
            ->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }

    private function actingAsTeacher(): static
    {
        $this->actingAs($this->teacher)
            ->withSession(['active_role_assignment_id' => $this->teacherContext->id]);

        return $this;
    }
}
