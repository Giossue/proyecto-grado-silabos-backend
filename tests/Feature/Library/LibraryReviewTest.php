<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Application\Actions\SignSyllabusDecision;
use App\Modules\Documents\Application\DocumentBundleValidator;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DecisionSignature;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Mail\CoordinatorSummaryMail;
use App\Modules\Operations\Infrastructure\Mail\DailySummaryMail;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
            'nombres' => 'Bibliotecaria Luz', 'apellidos' => 'Nueva Prueba',
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
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
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
        $this->assertNotified($this->people['librarian'][0]->id, 'proceso.eliminado', 'Mensaje de Administración: Se abrirá otra convocatoria.');
        $this->assertNotified($this->people['coordinator'][0]->id, 'proceso.eliminado', 'Mensaje de Administración: Se abrirá otra convocatoria.');
        $this->assertNotified($this->people['teacher'][0]->id, 'proceso.eliminado', 'Mensaje de Administración: Se abrirá otra convocatoria.');
    }

    public function test_dos_carreras_con_el_mismo_nombre_se_distinguen_por_su_codigo_en_pantalla(): void
    {
        [$syllabus] = $this->submittedRevision();
        $career = $syllabus->convocation->career()->firstOrFail();
        $this->assertSame($career->nombre, $career->label());
        $other = Career::query()->create([
            'facultad_id' => $career->facultad_id,
            'codigo_carrera' => 'MALLA-9',
            'nombre' => $career->nombre,
            'activo' => true,
            'cantidad_ciclos_malla' => 9,
        ]);
        $label = "{$career->nombre} · {$career->codigo_carrera}";

        $this->assertSame($label, $career->label());
        $this->assertSame("{$career->nombre} · MALLA-9", $other->label());
        $report = $this->as('admin')->getJson(route('api.v1.admin.reports.show'))->assertOk()->json('report');
        $this->assertSame($label, $report['careers'][0]['career']);
        $careers = $this->as('admin')->getJson(route('api.v1.admin.users.index'))->assertOk()->json('careers');
        $this->assertContains($label, array_column($careers, 'nombre'));
        $this->assertContains("{$career->nombre} · MALLA-9", array_column($careers, 'nombre'));
        // El sílabo sigue con el nombre oficial, sin el código.
        $this->assertSame($career->nombre, $syllabus->fresh()?->convocation->career->nombre);
        // El rol activo de la sesión también lleva el código, aunque la carrera se cargue sin él.
        $this->as('coordinator')->getJson(route('api.v1.session.show'))->assertOk()->assertJsonFragment(['career_name' => $label]);
        $this->assertSame($label, Career::query()->select(['carrera_id', 'nombre_carrera'])->findOrFail($career->id)->label());
    }

    public function test_el_reporte_de_la_convocatoria_resume_el_avance_por_carrera_y_se_exporta_a_excel(): void
    {
        [$syllabus] = $this->submittedRevision();
        $career = $syllabus->convocation->career()->firstOrFail()->nombre;

        $report = $this->as('admin')->getJson(route('api.v1.admin.reports.show'))->assertOk()->json('report');
        $this->assertSame(1, $report['kpis']['total']);
        $this->assertEquals(100, $report['kpis']['on_time_percent']);
        $this->assertSame($career, $report['careers'][0]['career']);
        $this->assertSame(1, $report['careers'][0]['states']['en_revision']);
        $this->assertSame(1, $report['daily'][0]['first']);
        $this->assertSame('en_revision', $report['pending'][0]['state']);

        $response = $this->as('admin')->get(route('api.v1.admin.reports.export'))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'report').'.xlsx';
        file_put_contents($file, $response->streamedContent());
        $book = IOFactory::load($file);
        unlink($file);
        $this->assertSame(['Resumen', 'Por carrera', 'Envíos por día', 'Pendientes'], $book->getSheetNames());
        $this->assertSame($career, $book->getSheetByName('Por carrera')->getCell('A2')->getValue());

        // La entrega vence al final del día en Ecuador, que en UTC ya es el día siguiente.
        $timezone = InstitutionDate::timezone();
        $syllabus->convocation->process()->firstOrFail()->update([
            'inicia_en' => CarbonImmutable::parse('2026-11-08', $timezone)->startOfDay()->utc(),
            'entrega_en' => CarbonImmutable::parse('2026-12-18', $timezone)->endOfDay()->utc(),
        ]);
        $process = $this->as('admin')->getJson(route('api.v1.admin.reports.show'))->assertOk()->json('report.process');
        $this->assertSame(['2026-11-08', '2026-12-18'], [$process['starts_at'], $process['due_at']]);

        $this->as('coordinator')->getJson(route('api.v1.admin.reports.show'))->assertForbidden();
        $this->as('librarian')->get(route('api.v1.admin.reports.export'))->assertForbidden();
    }

    public function test_cada_bibliotecario_filtra_su_bandeja_por_facultad(): void
    {
        [$syllabus] = $this->submittedRevision();
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 0);
        $own = $syllabus->convocation->career()->firstOrFail()->facultad_id;
        $other = Faculty::query()->create(['nombre' => 'Facultad de Ciencias de la Salud', 'campus' => 'Campus Matriz Dr. Gabriel Galarza López', 'activo' => true]);

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => $own]))
            ->assertOk()->assertJsonPath('reviews.total', 1)->assertJsonPath('filters.faculty', $own)
            ->assertJsonPath('reviews.data.0.faculty', Faculty::query()->findOrFail($own)->nombre)
            ->assertJsonFragment(['id' => $other->id, 'name' => 'Facultad de Ciencias de la Salud']);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => $other->id]))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['faculty' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors(['faculty']);
    }

    public function test_al_abrir_la_convocatoria_siguiente_sale_de_la_bandeja_y_pasa_al_historial_con_solo_los_mios(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('librarian')->getJson(route('api.v1.library.history.index'))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), ['reasons' => ['codigo_incorrecto']])->assertCreated();
        $period = $syllabus->convocation->process()->firstOrFail()->academicPeriod()->firstOrFail();

        // Aunque el período siga en clases, al abrir la convocatoria siguiente lo anterior pasa al historial.
        $this->openNextConvocation();

        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.dashboard.show'))->assertOk()->assertJsonPath('metrics.0.value', 0);
        $this->as('librarian')->getJson(route('api.v1.library.history.index'))->assertOk()
            ->assertJsonPath('reviews.total', 1)
            ->assertJsonFragment(['id' => $period->id, 'code' => $period->codigo]);
        $this->as('librarian')->getJson(route('api.v1.library.history.index', ['period' => $period->id]))->assertJsonPath('reviews.total', 1);
        $this->as('librarian')->getJson(route('api.v1.library.history.index', ['mine' => 1]))->assertJsonPath('reviews.total', 1)->assertJsonPath('filters.mine', true)
            ->assertJsonPath('reviews.data.0.state', 'atendido')->assertJsonPath('reviews.data.0.outcome', 'devuelto');
        $this->as('coordinator')->getJson(route('api.v1.library.history.index'))->assertForbidden();
    }

    public function test_la_cola_de_coordinacion_solo_muestra_la_convocatoria_actual(): void
    {
        [, $revision] = $this->submittedRevision();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 1);

        $this->openNextConvocation();

        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 0);
    }

    public function test_biblioteca_revisa_primero_y_devuelve_al_docente_que_reenvia_a_biblioteca(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->assertNotified($this->people['librarian'][0]->id, 'silabo.enviado', 'Revise la bibliografía del sílabo');
        $this->assertNotNotified($this->people['coordinator'][0]->id, 'biblioteca.verificado');
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 0);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.correction.store', $revision), [
            'justification' => 'Coordinación todavía no recibe esta revisión.',
        ])->assertJsonValidationErrors('revision');

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
        $this->assertNotified($this->people['teacher'][0]->id, 'silabo.correccion_solicitada', 'corríjalo y envíelo otra vez');
        $this->assertNotNotified($this->people['coordinator'][0]->id, 'biblioteca.correccion_solicitada');
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
        $this->assertSame(['devuelto_biblioteca', 'enviado'], $kinds);
        $returned = collect($coordinatorView->json('timeline'))->firstWhere('kind', 'devuelto_biblioteca');
        $this->assertNull($returned['by']);
        $this->assertNull($returned['comment']);

        $other = $this->teacherFields()->where('clave', '!=', 'bibliografia')->firstOrFail();
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
        $this->assertNotified($this->people['librarian'][0]->id, 'silabo.reenviado_biblioteca', 'El docente atendió su pedido');
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
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

    public function test_biblioteca_verifica_primero_coordinacion_aprueba_y_firman_docente_biblioteca_y_coordinacion(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        [$librarian, $coordinator] = [$this->people['librarian'][0], $this->people['coordinator'][0]];
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $coordinator), 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('errors.revision.0', 'La revisión ya no espera la decisión de coordinación.');
        $this->as('librarian')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $coordinator), 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $revision))->assertOk()->assertJsonPath('can_decide', false);

        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $librarian)])
            ->assertCreated()->assertJsonPath('message', 'Sílabo firmado y enviado a coordinación.');
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $librarian)])->assertCreated();
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('aprobaciones', 0);
        $this->assertDatabaseCount('revisiones_coordinacion', 0);
        $submission = DB::table('envios_biblioteca')->where('revision_silabo_id', $revision->id)->sole();
        $this->assertSame($librarian->id, $submission->verificado_por);
        $this->assertNotNull($submission->verificado_en);
        $this->assertFalse($submission->verificacion_heredada);
        $this->assertSame(1, DB::table('firmas_silabo')->where('firmado_como', 'biblioteca')->count());
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'coordinacion');
        $notice = $this->assertNotified($coordinator->id, 'biblioteca.verificado', 'Revise y apruebe el sílabo');
        $this->assertSame('Bibliografía verificada por Biblioteca', $notice->titulo);
        $this->as('coordinator')->postJson(route('api.v1.notifications.open', $notice))
            ->assertOk()->assertJsonPath('path', "/coordinacion/revisiones/{$revision->id}");
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.state', 'atendido')
            ->assertJsonPath('review.outcome', 'firmado')
            ->assertJsonPath('review.can_comment', false)
            ->assertJsonPath('review.can_verify', false)
            ->assertJsonPath('review.signing_statement', null)
            ->assertJsonPath('review.signatures.*.role', ['docente', 'biblioteca'])
            ->assertJsonPath('review.verified_by', $librarian->nombre)
            ->assertJsonPath('review.approved_at', null);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), [
            'reasons' => ['codigo_incorrecto'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('revision');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'atendido']))
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.outcome', 'firmado')
            ->assertJsonPath('reviews.data.0.signed_by', $librarian->nombre);
        $this->as('librarian')->getJson(route('api.v1.dashboard.show'))->assertOk()
            ->assertJsonPath('metrics.*.key', ['library_pending', 'library_signed', 'library_commented'])
            ->assertJsonPath('metrics.*.value', [0, 1, 0]);
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()
            ->assertJsonPath('syllabi.total', 1)->assertJsonPath('syllabi.data.0.stage', 'coordinacion');
        $view = $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $revision))->assertOk()
            ->assertJsonPath('can_decide', true)
            ->assertJsonPath('timeline.0.kind', 'biblioteca_verifico')
            ->assertJsonPath('timeline.0.by', $librarian->nombre);
        $this->assertNotNull($view->json('signing_statement'));

        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $coordinator), 'idempotency_key' => (string) Str::uuid()])
            ->assertSuccessful()->assertJsonPath('message', 'Sílabo firmado y aprobado.');
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $coordinator), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $approval = Approval::query()->sole();
        $this->assertSame($coordinator->id, $approval->aprobado_por);
        $this->assertSame(64, strlen($approval->huella_sha256));
        $this->assertDatabaseHas('revisiones_coordinacion', ['revision_silabo_id' => $revision->id, 'revisado_por' => $coordinator->id]);
        $this->assertSame(1, ExportArtifact::query()->count());
        $this->assertNotNotified($this->people['teacher'][0]->id, 'silabo.aprobado');
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $revision))
            ->assertJsonPath('review.outcome', 'firmado')
            ->assertJsonPath('review.signatures.*.role', ['docente', 'biblioteca', 'coordinacion'])
            ->assertJsonPath('review.approved_at', $approval->aprobado_en->toIso8601String());
        $timeline = $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $revision))->assertOk()->json('timeline');
        $this->assertSame(['aprobado', 'biblioteca_verifico', 'enviado'], array_column($timeline, 'kind'));
        $this->assertSame($coordinator->nombre, $timeline[0]['by']);
        $variables = $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk()->json('syllabus.template_variables');
        $verifiedOn = CarbonImmutable::parse($submission->verificado_en)->setTimezone('America/Guayaquil')->format('d-m-Y');
        $this->assertSame($librarian->nombre, $variables['nombre_bibliotecario']);
        $this->assertSame($verifiedOn, $variables['fecha_verificacion']);
        $this->assertSame($coordinator->nombre, $variables['nombre_coordinador']);
        $this->assertSame($approval->aprobado_en->setTimezone('America/Guayaquil')->format('d-m-Y'), $variables['fecha_revision']);

        (new GenerateSyllabusExportJob(ExportArtifact::query()->latest('solicitado_en')->firstOrFail()->id))->handle(
            app(DocumentRenderer::class),
            app(RecordAuditEvent::class),
            app(DocumentBundleValidator::class),
        );
        $this->assertNotified($this->people['teacher'][0]->id, 'silabo.aprobado', 'Ya puede descargar el PDF firmado');
        $this->assertNotNotified($coordinator->id, 'silabo.aprobado');
        $this->assertNotNotified($coordinator->id, 'biblioteca.aprobado');
        $this->assertNotNotified($librarian->id, 'coordinacion.aprobado');
    }

    public function test_si_coordinacion_devuelve_y_la_bibliografia_no_cambio_el_reenvio_va_directo_a_coordinacion_con_la_verificacion_anterior(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        [$librarian, $coordinator] = [$this->people['librarian'][0], $this->people['coordinator'][0]];
        $this->verify($revision);
        $verifiedAt = DB::table('envios_biblioteca')->where('revision_silabo_id', $revision->id)->value('verificado_en');
        $this->travel(2)->days();
        $this->returnFromCoordination($revision, 'Ajuste la descripción de la asignatura.');

        $other = $this->teacherFields()->whereNotIn('clave', ['bibliografia', 'bibliografia_complementaria'])->firstOrFail();
        $second = $this->resubmit($syllabus, $other, 'Descripción ajustada');

        $carried = DB::table('envios_biblioteca')->where('revision_silabo_id', $second->id)->sole();
        $this->assertSame($librarian->id, $carried->verificado_por);
        $this->assertSame($verifiedAt, $carried->verificado_en);
        $this->assertTrue($carried->verificacion_heredada);
        $this->assertSame(1, InternalNotification::query()->where('tipo', 'silabo.enviado')->count());
        $this->assertFalse(InternalNotification::query()->where('tipo', 'silabo.reenviado_biblioteca')->exists());
        $this->assertSame(1, InternalNotification::query()->where('tipo', 'biblioteca.verificado')->count());
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'coordinacion');
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()
            ->assertJsonPath('syllabi.total', 1)->assertJsonPath('syllabi.data.0.revision_id', $second->id)->assertJsonPath('syllabi.data.0.stage', 'coordinacion');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.revision_id', $revision->id)->assertJsonPath('reviews.data.0.outcome', 'firmado');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))->assertJsonPath('reviews.total', 0);
        $this->as('librarian')->getJson(route('api.v1.dashboard.show'))->assertJsonPath('metrics.*.value', [0, 1, 0]);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $second))->assertOk()
            ->assertJsonPath('review.state', 'atendido')->assertJsonPath('review.outcome', 'firmado')
            ->assertJsonPath('review.can_verify', false)->assertJsonPath('review.verified_by', $librarian->nombre);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $second), ['reasons' => ['codigo_incorrecto']])->assertJsonValidationErrors('revision');

        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $second), ['signature' => $this->revisionSignature($second, 'coordinacion', $coordinator), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->assertSame(1, DB::table('firmas_silabo')->where('firmado_como', 'biblioteca')->count());
        $variables = $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk()->json('syllabus.template_variables');
        $this->assertSame($librarian->nombre, $variables['nombre_bibliotecario']);
        $this->assertSame(CarbonImmutable::parse($verifiedAt)->setTimezone('America/Guayaquil')->format('d-m-Y'), $variables['fecha_verificacion']);
        $this->assertSame(now()->setTimezone('America/Guayaquil')->format('d-m-Y'), $variables['fecha_revision']);
        $kinds = array_column($this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $second))->json('timeline'), 'kind');
        $this->assertSame(['aprobado', 'enviado', 'correccion', 'biblioteca_verifico', 'enviado'], $kinds);
    }

    public function test_si_coordinacion_devuelve_y_el_docente_cambia_la_bibliografia_el_reenvio_vuelve_a_biblioteca(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $librarian = $this->people['librarian'][0];
        $this->verify($revision);
        $this->returnFromCoordination($revision, 'Actualice la bibliografía básica.');

        $second = $this->resubmit($syllabus, $this->field('bibliografia'), 'Martin, R. Arquitectura limpia (2.ª ed.).');

        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $second->id, 'verificado_por' => null, 'verificado_en' => null, 'verificacion_heredada' => false]);
        $this->assertSame(2, InternalNotification::query()->where('usuario_id', $librarian->id)->where('tipo', 'silabo.enviado')->count());
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))->assertOk()
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.revision_id', $second->id)
            ->assertJsonPath('reviews.data.0.state', 'pendiente')->assertJsonPath('reviews.data.0.responsible', $librarian->nombre);

        $this->as('librarian')->getJson(route('api.v1.library.reviews.show', $second))->assertOk()
            ->assertJsonPath('review.can_verify', true)->assertJsonPath('review.signing_statement', null);
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $second))->assertCreated();
        $this->assertSame(1, DB::table('firmas_silabo')->where('firmado_como', 'biblioteca')->count());
        $this->assertNotNull(DB::table('envios_biblioteca')->where('revision_silabo_id', $second->id)->value('verificado_en'));
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'coordinacion');
        $this->assertSame(2, InternalNotification::query()->where('tipo', 'biblioteca.verificado')->count());
    }

    public function test_al_cambiar_el_coordinador_se_pierde_solo_la_aprobacion_y_la_firma_de_coordinacion(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $librarian = $this->people['librarian'][0];
        $this->verify($revision);
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['signature' => $this->revisionSignature($revision, 'coordinacion', $this->people['coordinator'][0]), 'idempotency_key' => (string) Str::uuid()])->assertSuccessful();
        $incoming = User::factory()->create();

        $this->as('admin')->patchJson(route('api.v1.admin.users.update', $incoming), [
            'nombre' => $incoming->nombre, 'correo_electronico' => $incoming->correo_electronico,
            'role_code' => 'coordinador', 'career_id' => $syllabus->convocation->carrera_id, 'replace_coordinator' => true,
        ])->assertOk();

        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertDatabaseCount('aprobaciones', 0);
        $this->assertDatabaseCount('revisiones_coordinacion', 0);
        $this->assertDatabaseMissing('firmas_silabo', ['firmado_como' => 'coordinacion']);
        $this->assertDatabaseHas('firmas_silabo', ['firmado_como' => 'biblioteca', 'usuario_id' => $librarian->id]);
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $revision->id, 'verificado_por' => $librarian->id]);
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'coordinacion');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))->assertJsonPath('reviews.total', 0);
    }

    public function test_la_migracion_pasa_a_biblioteca_lo_que_esperaba_a_coordinacion_sin_tocar_la_plantilla(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->migration()->down();
        $this->assertDatabaseCount('envios_biblioteca', 0);
        $template = DB::table('plantilla_silabo')->get()->toArray();

        $this->migration()->up();

        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $revision->id, 'verificado_por' => null, 'verificacion_heredada' => false]);
        $this->assertEquals($template, DB::table('plantilla_silabo')->get()->toArray());
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertOk()->assertJsonPath('syllabi.total', 0);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))->assertOk()
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.revision_id', $revision->id);
        $this->assertDatabaseCount('envios_biblioteca', 1);
    }

    public function test_tras_migrar_lo_que_coordinacion_ya_firmo_pasa_por_biblioteca_y_coordinacion_aprueba_sin_volver_a_firmar(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $coordinator = $this->people['coordinator'][0];
        $this->as('coordinator');
        app(SignSyllabusDecision::class)->execute($revision, 'coordinacion', DecisionSignature::from($this->revisionSignature($revision, 'coordinacion', $coordinator)), $coordinator, request());
        $reviewedAt = now()->subDay()->startOfSecond();
        DB::table('revisiones_coordinacion')->insert(['revision_coordinacion_id' => (string) Str::uuid(), 'revision_silabo_id' => $revision->id, 'revisado_por' => $coordinator->id, 'revisado_en' => $reviewedAt]);
        $this->migration()->down();
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $revision->id]);

        $this->migration()->up();

        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.index'))->assertJsonPath('syllabi.total', 0);
        $this->verify($revision);
        $this->as('coordinator')->getJson(route('api.v1.coordination.reviews.show', $revision))->assertOk()
            ->assertJsonPath('can_decide', true)->assertJsonPath('signing_statement', null);
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.approve', $revision), ['idempotency_key' => (string) Str::uuid()])->assertSuccessful();

        $this->assertSame('aprobado', $syllabus->fresh()->estado);
        $this->assertSame(1, DB::table('firmas_silabo')->where('firmado_como', 'coordinacion')->count());
        $this->assertSame($coordinator->id, Approval::query()->sole()->aprobado_por);
        $this->assertSame(1, DB::table('revisiones_coordinacion')->count());
        $this->assertSame($reviewedAt->setTimezone('America/Guayaquil')->format('d-m-Y'), $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->json('syllabus.template_variables.fecha_revision'));
    }

    public function test_tras_migrar_el_reenvio_que_biblioteca_devolvio_sigue_en_biblioteca(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->as('librarian')->postJson(route('api.v1.library.reviews.comments.store', $revision), ['reasons' => ['codigo_incorrecto']])->assertCreated();
        $second = $this->resubmit($syllabus, $this->field('bibliografia'), 'Pressman, R. Ingeniería del software (9.ª ed.).');
        $this->migration()->down();
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $second->id]);

        $this->migration()->up();

        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index', ['state' => 'pendiente']))
            ->assertJsonPath('reviews.total', 1)->assertJsonPath('reviews.data.0.revision_id', $second->id);
        $this->assertDatabaseMissing('envios_biblioteca', ['revision_silabo_id' => $revision->id]);
    }

    public function test_tras_migrar_un_silabo_aprobado_por_biblioteca_conserva_quien_verifico_y_las_fechas_del_pdf(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        [$librarian, $coordinator] = [$this->people['librarian'][0], $this->people['coordinator'][0]];
        $this->migration()->down();
        $reviewedAt = CarbonImmutable::parse('2026-11-03 15:00:00', 'UTC');
        $approvedAt = CarbonImmutable::parse('2026-11-05 16:30:00', 'UTC');
        DB::table('revisiones_coordinacion')->insert(['revision_coordinacion_id' => (string) Str::uuid(), 'revision_silabo_id' => $revision->id, 'revisado_por' => $coordinator->id, 'revisado_en' => $reviewedAt]);
        DB::table('envios_biblioteca')->insert(['envio_biblioteca_id' => (string) Str::uuid(), 'revision_silabo_id' => $revision->id]);
        Approval::query()->create([
            'revision_silabo_id' => $revision->id, 'clave_idempotencia' => (string) Str::uuid(), 'huella_sha256' => str_repeat('a', 64),
            'aprobado_por' => $librarian->id, 'aprobado_en' => $approvedAt,
        ]);
        DB::table('silabos')->where('silabo_id', $syllabus->id)->update(['estado_silabo' => 'aprobado']);

        $this->migration()->up();

        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $revision->id, 'verificado_por' => $librarian->id, 'verificacion_heredada' => false]);
        $this->assertSame($approvedAt->getTimestamp(), CarbonImmutable::parse(DB::table('envios_biblioteca')->value('verificado_en'))->getTimestamp());
        $variables = $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk()->json('syllabus.template_variables');
        $this->assertSame($librarian->nombre, $variables['nombre_bibliotecario']);
        $this->assertSame('05-11-2026', $variables['fecha_verificacion']);
        $this->assertSame($coordinator->nombre, $variables['nombre_coordinador']);
        $this->assertSame('03-11-2026', $variables['fecha_revision']);
        $this->as('librarian')->getJson(route('api.v1.library.reviews.index'))
            ->assertJsonPath('reviews.data.0.outcome', 'firmado')->assertJsonPath('reviews.data.0.signed_by', $librarian->nombre);

        $this->migration()->down();
        $this->assertSame(1, Approval::query()->count());
        $this->migration()->up();
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $revision->id, 'verificado_por' => $librarian->id]);
    }

    public function test_tras_migrar_la_correccion_pedida_sigue_igual_y_el_reenvio_va_a_biblioteca(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->migration()->down();
        DB::table('revisiones_silabo')->where('revision_silabo_id', $revision->id)->update([
            'correccion_justificacion' => 'Falta alinear los resultados.', 'correccion_etapa' => 'coordinacion',
            'correccion_solicitada_por' => $this->people['coordinator'][0]->id, 'correccion_solicitada_en' => now(),
        ]);
        DB::table('silabos')->where('silabo_id', $syllabus->id)->update(['estado_silabo' => 'correccion_solicitada']);

        $this->migration()->up();

        $this->assertDatabaseCount('envios_biblioteca', 0);
        $this->assertSame('correccion_solicitada', $syllabus->fresh()->estado);
        $other = $this->teacherFields()->whereNotIn('clave', ['bibliografia', 'bibliografia_complementaria'])->firstOrFail();
        $second = $this->resubmit($syllabus, $other, 'Resultados alineados');
        $this->assertDatabaseHas('envios_biblioteca', ['revision_silabo_id' => $second->id, 'verificado_por' => null]);
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'biblioteca');
    }

    public function test_la_migracion_no_se_revierte_con_silabos_que_el_orden_anterior_no_representa(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
        $this->verify($revision);

        try {
            $this->migration()->down();
            $this->fail('La migración se revirtió con una verificación de biblioteca sin aprobación.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('El orden anterior no puede representarlos', $exception->getMessage());
        }
        $this->assertNotNull(DB::table('envios_biblioteca')->where('revision_silabo_id', $revision->id)->value('verificado_en'));
        $this->as('teacher')->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertJsonPath('syllabus.review_stage', 'coordinacion');
    }

    public function test_con_otro_motivo_biblioteca_escribe_el_texto_y_se_une_a_los_motivos_marcados(): void
    {
        [$syllabus, $revision] = $this->submittedRevision();
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
        $this->assertNull(DB::table('envios_biblioteca')->where('revision_silabo_id', $revision->id)->value('verificado_en'));

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
        Mail::assertQueued(DailySummaryMail::class, fn (DailySummaryMail $mail): bool => $mail->hasTo('bibliotecario@silabos.test')
            && str_contains($mail->lines[0], '1 sílabo por revisar'));

        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
        Mail::fake();
        Cache::flush();
        Artisan::call('avisos:resumen-diario');
        Mail::assertNotQueued(DailySummaryMail::class, fn (DailySummaryMail $mail): bool => $mail->hasTo('bibliotecario@silabos.test'));
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
        Mail::assertNotQueued(CoordinatorSummaryMail::class);

        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
        Cache::flush();
        Artisan::call('avisos:resumen-coordinacion');
        Artisan::call('avisos:resumen-coordinacion');
        Mail::assertQueued(CoordinatorSummaryMail::class, 1);
        Mail::assertQueued(CoordinatorSummaryMail::class, fn (CoordinatorSummaryMail $mail): bool => $mail->hasTo('coordinador@silabos.test')
            && $mail->careers[0]['review'] === 1);

        Queue::fake([GenerateSyllabusExportJob::class]);
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
        foreach ($this->teacherFields() as $field) {
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

    private function verify(SyllabusRevision $revision): void
    {
        $this->as('librarian')->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $this->people['librarian'][0])])->assertCreated();
    }

    private function returnFromCoordination(SyllabusRevision $revision, string $justification): void
    {
        $this->as('coordinator')->postJson(route('api.v1.coordination.reviews.correction.store', $revision), ['justification' => $justification])->assertSuccessful();
    }

    private function resubmit(Syllabus $syllabus, FieldDefinition $field, string $text): SyllabusRevision
    {
        $this->as('teacher')->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'value' => $this->answeredValue($field, $text),
        ])->assertOk();
        $this->as('teacher')->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->people['teacher'][0]),
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        return SyllabusRevision::query()->where('silabo_id', $syllabus->id)->orderByDesc('numero_revision')->firstOrFail();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_11_000269_library_verifies_before_coordination.php');
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
