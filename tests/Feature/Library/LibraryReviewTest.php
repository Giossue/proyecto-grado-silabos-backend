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
use App\Modules\Operations\Infrastructure\Mail\CoordinatorSummaryMail;
use App\Modules\Operations\Infrastructure\Mail\DailySummaryMail;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\InstitutionDate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\TestCase;

class LibraryReviewTest extends TestCase
{
    use AssertsInternalNotifications;
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use SignsStatements;

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

    public function test_al_eliminar_una_convocatoria_se_borran_hasta_los_silabos_aprobados_y_firmados_y_biblioteca_recibe_el_aviso(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
        (new GenerateSyllabusExportJob(ExportArtifact::query()->latest('solicitado_en')->firstOrFail()->id))->handle(
            app(DocumentRenderer::class),
            app(RecordAuditEvent::class),
            app(DocumentBundleValidator::class),
        );
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $pdf = DB::table('archivos')->whereIn('archivo_id', DB::table('exportaciones_silabo')->whereNotNull('archivo_pdf_id')->select('archivo_pdf_id'))->sole();
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'atendido']))->assertOk()->assertJsonPath('reviews.total', 1);

        $process = $syllabus->convocation->process()->firstOrFail();
        $this->as('admin')->getJson(route('api.v1.admin.processes.impact', $process))
            ->assertOk()->assertJsonPath('syllabi.aprobado', 1);
        $this->as('admin')->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Se abrirá otra convocatoria.'])
            ->assertOk();

        $this->assertDatabaseMissing('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        foreach (['silabos', 'revisiones_silabo', 'aprobaciones', 'firmas_silabo', 'envios_biblioteca', 'revisiones_coordinacion', 'exportaciones_silabo'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('archivos', ['archivo_id' => $pdf->archivo_id]);
        Storage::disk($pdf->disco)->assertMissing($pdf->ruta_archivo);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'atendido']))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->assertNotified($this->people['librarian'][0]->id, 'proceso.eliminado', 'Mensaje: Se abrirá otra convocatoria.');
        $this->assertNotified($this->people['coordinator'][0]->id, 'proceso.eliminado', 'Mensaje: Se abrirá otra convocatoria.');
        $this->assertNotified($this->people['teacher'][0]->id, 'proceso.eliminado', 'Mensaje: Se abrirá otra convocatoria.');
    }

    public function test_cada_bibliotecario_filtra_su_bandeja_por_facultad(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 1)->assertJsonPath('syllabi.data.0.stage', 'coordinacion');
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 0);
        $own = $syllabus->convocation->career()->firstOrFail()->facultad_id;
        $other = Faculty::query()->create(['nombre' => 'Facultad de Ciencias de la Salud', 'campus' => 'Campus Matriz Dr. Gabriel Galarza López', 'activo' => true]);

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => $own]))
            ->assertOk()->assertJsonPath('reviews.total', 1)->assertJsonPath('filters.faculty', $own)
            ->assertJsonFragment(['id' => $other->id, 'name' => 'Facultad de Ciencias de la Salud']);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => $other->id]))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors(['faculty']);
    }

    public function test_al_terminar_el_periodo_sale_de_la_bandeja_y_pasa_al_historial_con_solo_los_mios(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->as('librarian')->getJson(route('api.v1.library.history.index'))->assertOk()->assertJsonPath('reviews.total', 0);

        $period = $syllabus->convocation->process()->firstOrFail()->academicPeriod()->firstOrFail();
        DB::table('periodos_academicos')->where('periodo_academico_id', $period->id)->update(['fecha_fin_periodo' => InstitutionDate::today()->subDay()->toDateString()]);

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.dashboard.show'))->assertOk()->assertJsonPath('metrics.0.value', 0);
        $this->as('librarian')->getJson(route('api.v1.library.history.index'))->assertOk()
            ->assertJsonPath('reviews.total', 1)
            ->assertJsonFragment(['id' => $period->id, 'code' => $period->codigo]);
        $this->as('librarian')->getJson(route('api.v1.library.history.index', ['period' => $period->id]))->assertJsonPath('reviews.total', 1);
        $this->as('librarian')->getJson(route('api.v1.library.history.index', ['mine' => 1]))->assertJsonPath('reviews.total', 0)->assertJsonPath('filters.mine', true);

        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), ['reasons' => ['codigo_incorrecto']])->assertCreated();
        $this->as('librarian')->getJson(route('api.v1.library.history.index', ['mine' => 1]))->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.state', 'atendido')->assertJsonPath('reviews.data.0.outcome', 'devuelto');
        $this->as('coordinator')->getJson(route('api.v1.library.history.index'))->assertForbidden();
    }

    public function test_biblioteca_revisa_tras_coordinacion_y_devuelve_al_docente_que_reenvia_directo(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->assertNotified($this->people['librarian'][0]->id, 'coordinacion.aprobado', 'Falta que biblioteca apruebe');

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))
            ->assertOk()
            ->assertJsonPath('reviews.total', 1)
            ->assertJsonPath('reviews.data.0.revision_id', $revision->id)
            ->assertJsonPath('reviews.data.0.state', 'pendiente')
            ->assertJsonPath('reviews.data.0.teachers.0', $this->people['teacher'][0]->nombre);
        $teacherName = mb_strtoupper($this->people['teacher'][0]->nombre);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['q' => $teacherName]))->assertOk()->assertJsonPath('reviews.total', 1);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['q' => 'no-existe-nadie']))->assertOk()->assertJsonPath('reviews.total', 0);
        $detail = $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('review.can_comment', true)
            ->assertJsonPath('review.can_verify', true)
            ->assertJsonPath('review.sections.0.key', 'bibliografia')
            ->assertJsonCount(1, 'review.sections');
        $this->assertNotEmpty($detail->json('review.template_variables'));
        $this->assertNull($detail->json('review.syllabus_sections'));
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
        $notice = $this->assertNotified($this->people['coordinator'][0]->id, 'biblioteca.correccion_solicitada', 'Biblioteca pidió corregir la bibliografía');
        $this->as('coordinator')->postJson(route('api.v1.notifications.open', $notice))
            ->assertOk()->assertJsonPath('path', "/coordinacion/revisiones/{$revision->id}");
        $this->assertSame('correccion_solicitada', $syllabus->fresh()->estado);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertJsonPath('reviews.data.0.state', 'atendido')->assertJsonPath('reviews.data.0.outcome', 'devuelto');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.can_comment', false)
            ->assertJsonPath('review.comments.0.comment', 'El libro no está disponible en biblioteca; El código del libro es incorrecto.');
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertJsonPath('syllabus.review_comments.0.stage', 'biblioteca')
            ->assertJsonPath('syllabus.review_comments.0.comment', 'El libro no está disponible en biblioteca; El código del libro es incorrecto.');

        $coordinatorView = $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $revision))->assertOk()
            ->assertJsonPath('correction_request.stage', 'biblioteca')
            ->assertJsonPath('correction_request.justification', null)
            ->assertJsonPath('correction_request.requested_by', null);
        $this->assertArrayNotHasKey('library_verified_by', $coordinatorView->json('history.0'));
        $tracking = $this->as('coordinator')->getJson(route('api.v1.coordination.convocations.show', $syllabus->convocation))->assertOk();
        $this->assertSame('biblioteca', collect($tracking->json('convocation.syllabi'))->firstWhere('id', $syllabus->id)['returned_by']);
        $this->assertStringNotContainsString('El libro no está disponible', (string) $coordinatorView->getContent());
        $kinds = array_column($coordinatorView->json('timeline'), 'kind');
        $this->assertSame(['devuelto_biblioteca', 'coordinacion_aprobo', 'enviado'], $kinds);
        $returned = collect($coordinatorView->json('timeline'))->firstWhere('kind', 'devuelto_biblioteca');
        $this->assertNull($returned['by']);
        $this->assertNull($returned['comment']);

        $other = FieldDefinition::query()->where('quien_llena', 'docente')
            ->where('clave', '!=', 'bibliografia')->firstOrFail();
        $this->as('teacher')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $other]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->answeredValue($other, 'Ajuste general'),
        ])->assertOk();
        $this->as('teacher')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $this->field('bibliografia')]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->answeredValue($this->field('bibliografia'), 'Martin, R. Arquitectura limpia (2.ª ed.).'),
        ])->assertOk();
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->people['teacher'][0]),
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
        // Un sílabo aparece una sola vez, con su envío más reciente; el anterior ya no figura como «devuelto».
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.revision_id', $corrected->id)
            ->assertJsonPath('reviews.data.0.responsible', $this->people['librarian'][0]->nombre);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'atendido']))->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'devuelto']))->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'firmado']))->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.dashboard.show'))->assertJsonPath('metrics.*.value', [1, 0, 0]);

        // Quien devolvió el sílabo figura como responsable; otro bibliotecario puede actuar, pero la pantalla le avisa.
        $other = User::factory()->create();
        $otherAssignment = RoleAssignment::query()->create(['usuario_id' => $other->id, 'rol' => RoleCode::Librarian->value, 'carrera_id' => null, 'activo' => true]);
        $this->people['other_librarian'] = [$other, $otherAssignment];
        $this->as('other_librarian')->getJson(route('api.v1.library.reviews.show', $corrected))->assertOk()
            ->assertJsonPath('review.can_comment', true)->assertJsonPath('review.can_verify', true)
            ->assertJsonPath('review.assigned_to_other', true)->assertJsonPath('review.responsible', $this->people['librarian'][0]->nombre);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $corrected))->assertOk()
            ->assertJsonPath('review.can_comment', true)->assertJsonPath('review.assigned_to_other', false);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'anulado']))->assertUnprocessable();
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
    }

    public function test_coordinacion_aprueba_primero_biblioteca_aprueba_y_firman_docente_coordinacion_y_biblioteca(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'coordinacion');
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertForbidden();
        $this->as('librarian')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');

        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->assertSame($this->people['librarian'][0]->id, Approval::query()->firstOrFail()->aprobado_por);
        $this->assertNotNotified($this->people['teacher'][0]->id, 'silabo.aprobado');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.state', 'atendido')
            ->assertJsonPath('review.outcome', 'firmado')
            ->assertJsonPath('review.can_comment', false)
            ->assertJsonPath('review.signing_statement', null)
            ->assertJsonPath('review.signatures.0.role', 'docente')
            ->assertJsonPath('review.signatures.1.role', 'coordinacion')
            ->assertJsonPath('review.signatures.2.role', 'biblioteca')
            ->assertJsonPath('review.verified_by', $this->people['librarian'][0]->nombre);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('revision');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'atendido']))
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.outcome', 'firmado');
        $this->as('librarian')->getJson(route('api.v1.dashboard.show'))->assertOk()
            ->assertJsonPath('metrics.*.key', ['library_pending', 'library_signed', 'library_commented'])
            ->assertJsonPath('metrics.*.value', [0, 1, 0]);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'por_firmar']))->assertUnprocessable();
        $this->as('librarian')->getJson(route('api.v1.library.history.index', ['mine' => 1]))->assertOk();

        (new GenerateSyllabusExportJob(ExportArtifact::query()->latest('solicitado_en')->firstOrFail()->id))->handle(
            app(DocumentRenderer::class),
            app(RecordAuditEvent::class),
            app(DocumentBundleValidator::class),
        );
        $this->assertNotified($this->people['teacher'][0]->id, 'silabo.aprobado', 'Ya puede descargar el PDF firmado');
        $this->assertNotNotified($this->people['coordinator'][0]->id, 'silabo.aprobado');
        $notice = $this->assertNotified($this->people['coordinator'][0]->id, 'biblioteca.aprobado', 'Biblioteca aprobó el sílabo');
        $this->as('coordinator')->postJson(route('api.v1.notifications.open', $notice))
            ->assertOk()->assertJsonPath('path', "/coordinacion/revisiones/{$revision->id}");
    }

    public function test_con_otro_motivo_biblioteca_escribe_el_texto_y_se_une_a_los_motivos_marcados(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0])])->assertSuccessful();
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
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $syllabus->convocation->update(['estado' => Convocation::STATE_CLOSED]);
        $message = 'La convocatoria está cerrada: la revisión queda solo para consulta y no admite decisiones.';

        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertOk()
            ->assertJsonPath('review.can_comment', false)
            ->assertJsonPath('review.can_verify', false);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('message', $message);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertForbidden();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertNull($revision->fresh()->correccion_etapa);
    }

    public function test_una_pausa_que_llega_tras_autorizar_igual_frena_la_decision_de_biblioteca_con_la_fila_bloqueada(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
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
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])
            ->assertUnprocessable()->assertJsonPath('errors.revision.0', $paused);
        $this->assertDatabaseCount('aprobaciones', 0);

        Convocation::query()->whereKey($syllabus->convocatoria_id)->update(['estado' => Convocation::STATE_OPEN]);
        $pauseOnNextTransaction();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), ['reasons' => ['codigo_incorrecto']])
            ->assertUnprocessable()->assertJsonPath('errors.revision.0', $paused);
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertNull($revision->fresh()->correccion_etapa);
    }

    public function test_biblioteca_recibe_un_resumen_diario_de_lo_que_espera_su_revision(): void
    {
        Mail::fake();
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $librarian->forceFill(['correo_verificado_en' => now()])->save();
        [, $revision] = $this->submittedRevision();

        Artisan::call('avisos:resumen-diario');
        Mail::assertNotQueued(DailySummaryMail::class, fn (DailySummaryMail $mail): bool => $mail->hasTo('bibliotecario@silabos.test'));

        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        Artisan::call('avisos:resumen-diario');
        Mail::assertQueued(DailySummaryMail::class, fn (DailySummaryMail $mail): bool => $mail->hasTo('bibliotecario@silabos.test')
            && str_contains($mail->lines[0], '1 sílabo por revisar'));
    }

    public function test_el_coordinador_que_apago_el_resumen_diario_no_lo_recibe(): void
    {
        Mail::fake();
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $coordinator->forceFill(['correo_verificado_en' => now(), 'preferencias_correo' => ['coordinacion.resumen_diario' => false]])->save();
        $this->submittedRevision();

        Artisan::call('avisos:resumen-coordinacion');

        Mail::assertNotQueued(CoordinatorSummaryMail::class);
    }

    public function test_el_resumen_diario_avisa_a_coordinacion_solo_de_lo_que_espera_su_revision(): void
    {
        Mail::fake();
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $coordinator->forceFill(['correo_verificado_en' => now()])->save();

        Artisan::call('avisos:resumen-coordinacion');
        Mail::assertNothingQueued();

        [, $revision] = $this->submittedRevision();
        Artisan::call('avisos:resumen-coordinacion');
        Artisan::call('avisos:resumen-coordinacion');
        Mail::assertQueued(CoordinatorSummaryMail::class, 1);
        Mail::assertQueued(CoordinatorSummaryMail::class, fn (CoordinatorSummaryMail $mail): bool => $mail->hasTo('coordinador@silabos.test')
            && $mail->careers[0]['review'] === 1);

        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        Cache::flush();
        Artisan::call('avisos:resumen-coordinacion');
        Mail::assertQueued(CoordinatorSummaryMail::class, 1);
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
            'signature' => $this->teacherSignature($syllabus, $this->people['teacher'][0]),
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revision = SyllabusRevision::query()->firstOrFail();

        return [$syllabus->fresh(), $revision->fresh()];
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
