<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\StoreInternalNotifications;
use App\Modules\Operations\Infrastructure\Mail\WorkflowNotificationMail;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class SyllabusProcessTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use SignsStatements;
    use UsesInstitutionalDays;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    private User $teacher;

    private RoleAssignment $teacherContext;

    public function test_convocation_tables_store_only_current_state_and_non_redundant_configuration(): void
    {
        foreach (['periodo_academico_id', 'plantilla_id', 'creado_por', 'abierto_por', 'abierto_en', 'cerrado_en', 'actualizado_en'] as $column) {
            $this->assertFalse(Schema::hasColumn('convocatorias_carreras', $column));
        }
        foreach (['creado_por', 'abierto_por', 'abierto_en', 'cerrado_en', 'actualizado_en'] as $column) {
            $this->assertFalse(Schema::hasColumn('convocatorias_universidad', $column));
        }

        $this->assertTrue(Schema::hasColumns('convocatorias_carreras', ['convocatoria_carrera_id', 'carrera_id', 'convocatoria_universidad_id', 'estado_convocatoria_carrera']));
        $this->assertTrue(Schema::hasColumns('convocatorias_universidad', ['convocatoria_universidad_id', 'periodo_academico_id', 'fecha_inicio', 'fecha_entrega', 'estado_convocatoria_universidad']));
        foreach (['silabos', 'convocatorias_universidad', 'exportaciones_silabo'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'plantilla_id'));
        }
        $this->assertFalse(Schema::hasColumn('convocatorias_carreras', 'creado_en'));
        $this->assertFalse(Schema::hasColumn('convocatorias_universidad', 'creado_en'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();

        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
    }

    public function test_opening_the_institutional_process_keeps_fixed_parallels_and_teachers(): void
    {
        $template = $this->publishedTemplate();
        $existing = Subject::query()->where('carrera_id', $this->coordinatorContext->carrera_id)->firstOrFail();
        $subject = Subject::query()->create([
            'carrera_id' => $existing->carrera_id,
            'codigo_asignatura' => 'SW-FIJA',
            'nombre' => 'Materia con paralelo fijo',
            'ciclo' => $existing->ciclo,
            'orden_en_ciclo' => 99,
            'activo' => true,
        ]);
        $parallel = Parallel::query()->create(['asignatura_id' => $subject->id, 'codigo' => 'B', 'jornada' => 'nocturna']);
        $parallel->teacherAssignments()->create(['asignacion_rol_id' => $this->teacherContext->id, 'activo' => true]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), $this->processPayload($template))
            ->assertSuccessful();

        $this->assertSame(1, Parallel::query()->where('asignatura_id', $subject->id)->count());
        $this->assertSame('nocturna', $parallel->fresh()?->jornada);
        $this->assertSame($this->teacherContext->id, $parallel->teacherAssignments()->where('activo', true)->value('asignacion_rol_id'));
        $this->assertDatabaseMissing('eventos_auditoria', ['accion' => 'academico.periodo_arrastrado']);
    }

    public function test_only_administration_opens_the_institutional_process(): void
    {
        $template = $this->publishedTemplate();

        $this->actingAsCoordinator()->postJson(route('api.v1.admin.processes.store'), $this->processPayload($template))
            ->assertForbidden();
        $this->actingAsCoordinator()->getJson(route('api.v1.admin.processes.index'))->assertForbidden();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), $this->processPayload($template))
            ->assertSuccessful()
            ->assertSuccessful();

        $process = SyllabusProcess::query()->firstOrFail();
        $this->assertSame('abierto', $process->estado);
        $this->assertSame($process->academicPeriod->codigo, $process->nombre);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'proceso_silabos.creado', 'recurso_id' => $process->id]);

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->has('processes', 1)
                ->where('processes.0.state', 'abierto'));
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.academic.index'))
            ->assertJsonPath('catalogs.periods.0.institutional_process.id', $process->id)
            ->assertJsonPath('catalogs.periods.0.institutional_process.state', SyllabusProcess::STATE_OPEN);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'proceso_silabos.creado',
            'recurso_id' => $process->id,
        ]);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'proceso_silabos.abierto',
            'recurso_id' => $process->id,
        ]);
    }

    public function test_database_rejects_the_removed_preparation_state(): void
    {
        $process = $this->openProcess();

        $this->expectException(QueryException::class);
        $process->update(['estado' => 'preparacion']);
    }

    public function test_a_closed_process_cannot_be_deleted_and_stays_as_history(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Se borra por error.'])
            ->assertForbidden();
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))
            ->assertOk()->assertJsonPath('processes.0.deletable', false);

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertNotEmpty(Syllabus::query()->where('convocatoria_id', $convocation->id)->pluck('id'));
        $this->assertFalse(InternalNotification::query()->where('tipo', 'proceso.eliminado')->exists());
    }

    public function test_deleting_a_process_without_sent_syllabi_erases_it_and_frees_the_period(): void
    {
        $template = $this->publishedTemplate();
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $syllabus = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Se abre de nuevo.'])
            ->assertOk()
            ->assertJsonPath('message', 'Convocatoria institucional eliminada.');

        $this->assertDatabaseMissing('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        $this->assertDatabaseMissing('convocatorias_carreras', ['convocatoria_carrera_id' => $convocation->id]);
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))
            ->assertOk()->assertJsonCount(0, 'processes');
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertOk()->assertJsonCount(0, 'syllabi.data');
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus->id))->assertNotFound();
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'proceso_silabos.eliminado', 'recurso_id' => $process->id]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [...$this->processPayload($template), 'period_id' => $process->periodo_academico_id])
            ->assertSuccessful();
        $this->assertSame(1, SyllabusProcess::query()->where('periodo_academico_id', $process->periodo_academico_id)->count());
    }

    public function test_a_process_with_sent_syllabi_is_deleted_with_all_its_work_and_everyone_involved_is_told(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $name = $process->nombre;
        $syllabus = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();
        $this->submitSyllabus($syllabus);
        $revisionIds = SyllabusRevision::query()->where('silabo_id', $syllabus->id)->pluck('id')->all();
        $this->assertNotEmpty($revisionIds);
        $total = Syllabus::query()->where('convocatoria_id', $convocation->id)->count();
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))
            ->assertOk()->assertJsonPath('processes.0.deletable', true);
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.impact', $process))
            ->assertOk()
            ->assertExactJson([
                'syllabi' => ['sin_iniciar' => $total - 1, 'borrador' => 0, 'en_revision' => 1, 'correccion_solicitada' => 0, 'aprobado' => 0],
                'total' => $total,
            ]);

        Mail::fake();
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => '  Abriremos otra con las fechas correctas.  '])
            ->assertOk()
            ->assertJsonPath('message', 'Convocatoria institucional eliminada.');

        $this->assertDatabaseMissing('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        $this->assertDatabaseMissing('convocatorias_carreras', ['convocatoria_carrera_id' => $convocation->id]);
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertSame(0, DB::table('revisiones_silabo')->whereIn('revision_silabo_id', $revisionIds)->count());
        $this->assertSame(0, DB::table('firmas_silabo')->where('silabo_id', $syllabus->id)->count());
        foreach ([$this->teacher, $this->coordinator, $librarian] as $person) {
            $notice = InternalNotification::query()->where('usuario_id', $person->id)->where('tipo', 'proceso.eliminado')->sole();
            $this->assertSame('Convocatoria eliminada', $notice->titulo);
            $this->assertSame("Se borraron todos los sílabos de {$name}. Mensaje de Administración: Abriremos otra con las fechas correctas.", $notice->mensaje);
            $this->assertSame($process->periodo_academico_id, $notice->periodo_academico_id);
            Mail::assertQueued(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo($person->correo_electronico) && $mail->title === 'Convocatoria eliminada');
        }
        Mail::assertQueued(WorkflowNotificationMail::class, 3);
        $this->assertFalse(InternalNotification::query()->where('usuario_id', $this->administrator->id)->where('tipo', 'proceso.eliminado')->exists());

        $listed = collect($this->actingAsTeacher()->getJson(route('api.v1.notifications.index'))->assertOk()->json('notifications.data'))
            ->firstWhere('tipo', 'proceso.eliminado');
        $this->assertIsArray($listed);
        $this->assertFalse($listed['has_destination']);
        $this->actingAsTeacher()->postJson(route('api.v1.notifications.open', $listed['id']))
            ->assertOk()->assertJsonPath('path', null);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'proceso_silabos.eliminado', 'recurso_id' => $process->id]);
    }

    public function test_deleting_a_process_asks_for_the_message_everyone_will_receive(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();

        foreach ([[], ['mensaje' => '   '], ['mensaje' => str_repeat('a', 1001)]] as $payload) {
            $this->actingAsAdministrator()
                ->deleteJson(route('api.v1.admin.processes.destroy', $process), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['mensaje']);
        }
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertJsonPath('errors.mensaje.0', 'Escriba el mensaje para docentes, coordinación y biblioteca.');

        $this->assertDatabaseHas('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        $this->assertDatabaseHas('convocatorias_carreras', ['convocatoria_carrera_id' => $convocation->id]);
        $this->assertFalse(InternalNotification::query()->where('tipo', 'proceso.eliminado')->exists());
    }

    public function test_coordination_cannot_delete_an_institutional_process(): void
    {
        $process = $this->createProcess($this->publishedTemplate(), 'Proceso protegido');

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Se borra.'])
            ->assertForbidden();
        $this->actingAsCoordinator()
            ->getJson(route('api.v1.admin.processes.impact', $process))
            ->assertForbidden();

        $this->assertDatabaseHas('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
    }

    public function test_a_paused_process_is_deleted_with_its_signatures_answers_and_syllabus_notices(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $syllabus = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();
        $this->submitSyllabus($syllabus);
        app(StoreInternalNotifications::class)->execute(
            'silabo.correccion_solicitada', 'silabo.correccion_solicitada:'.$syllabus->id, [$this->teacher->id], 'Corrija', 'Corrija el sílabo.', 'silabo', $syllabus->id,
        );
        $this->transition($process, 'pausar')->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_PAUSED, $process->fresh()?->estado);
        $ids = Syllabus::query()->where('convocatoria_id', $convocation->id)->pluck('id')->all();
        foreach (['firmas_silabo', 'valores_campo'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->whereIn('silabo_id', $ids)->count(), $table);
        }
        $this->assertGreaterThan(0, DB::table('notificaciones')->whereIn('recurso_id', $ids)->count());

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Pausada, se borra.'])
            ->assertOk();

        $this->assertDatabaseMissing('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        $this->assertSame(0, DB::table('convocatorias_carreras')->where('convocatoria_universidad_id', $process->id)->count());
        foreach (['silabos', 'revisiones_silabo', 'firmas_silabo', 'valores_campo'] as $table) {
            $this->assertSame(0, DB::table($table)->whereIn('silabo_id', $ids)->count(), $table);
        }
        $this->assertSame(0, DB::table('notificaciones')->whereIn('recurso_id', $ids)->count());
    }

    public function test_a_closed_process_with_sent_work_is_left_untouched_by_any_delete_attempt(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $this->submitSyllabus(Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail());
        $this->transition($process, 'cerrar')->assertSuccessful();
        $stored = $this->storedProcessWork();

        foreach ([['mensaje' => 'Se borra por error.'], []] as $payload) {
            $this->actingAsAdministrator()
                ->deleteJson(route('api.v1.admin.processes.destroy', $process), $payload)
                ->assertForbidden();
        }

        $this->assertSame($stored, $this->storedProcessWork());
        $this->assertFalse(InternalNotification::query()->where('tipo', 'proceso.eliminado')->exists());
    }

    public function test_a_teacher_can_neither_delete_a_process_nor_see_its_impact(): void
    {
        $process = $this->openedConvocation()->process()->firstOrFail();
        $stored = $this->storedProcessWork();

        $this->actingAsTeacher()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Se borra.'])
            ->assertForbidden();
        $this->actingAsTeacher()
            ->getJson(route('api.v1.admin.processes.impact', $process))
            ->assertForbidden();

        $this->assertSame($stored, $this->storedProcessWork());
    }

    public function test_the_deletion_impact_counts_syllabi_in_every_state(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $base = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();
        $notStarted = Syllabus::query()->where('convocatoria_id', $convocation->id)->count();
        foreach (['en_revision', 'correccion_solicitada', 'aprobado', 'aprobado', 'borrador', 'borrador', 'borrador'] as $index => $state) {
            $parallel = $base->parallel->replicate();
            $parallel->codigo = 'P'.$index;
            $parallel->save();
            Syllabus::query()->create([
                'convocatoria_id' => $convocation->id,
                'paralelo_id' => $parallel->id,
                'contexto_academico' => $base->contexto_academico,
                'estado' => $state,
            ]);
        }

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.impact', $process))
            ->assertOk()
            ->assertExactJson([
                'syllabi' => ['sin_iniciar' => $notStarted, 'borrador' => 3, 'en_revision' => 1, 'correccion_solicitada' => 1, 'aprobado' => 2],
                'total' => $notStarted + 7,
            ]);
    }

    public function test_the_deletion_notice_reaches_each_active_person_once(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $this->submitSyllabus(Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail());
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        RoleAssignment::query()->create(['usuario_id' => $this->coordinator->id, 'carrera_id' => null, 'rol' => 'bibliotecario', 'activo' => true]);
        $inactive = User::factory()->create();
        RoleAssignment::query()->create(['usuario_id' => $inactive->id, 'carrera_id' => null, 'rol' => 'bibliotecario', 'activo' => true]);
        $inactive->forceFill(['activo' => false])->save();
        Mail::fake();

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Aviso.'])
            ->assertOk();

        $this->assertSame(
            collect([$this->teacher->id, $this->coordinator->id, $librarian->id])->sort()->values()->all(),
            InternalNotification::query()->where('tipo', 'proceso.eliminado')->pluck('usuario_id')->sort()->values()->all(),
        );
        Mail::assertQueued(WorkflowNotificationMail::class, 3);
        $this->assertCount(1, Mail::queued(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo($this->coordinator->correo_electronico)));
        $this->assertCount(0, Mail::queued(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo($inactive->correo_electronico)));
    }

    public function test_every_role_opens_the_deletion_notice_and_earlier_process_notices_still_open(): void
    {
        $process = $this->openedConvocation()->process()->firstOrFail();
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => now()->addMonths(2)->toDateString()])
            ->assertOk();
        $earlier = InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo_recurso', 'proceso_silabos')->sole();

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => 'Lista.'])
            ->assertOk();

        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        foreach ([[$this->coordinator, $this->coordinatorContext->id], [$librarian, $librarian->roleAssignments()->firstOrFail()->id]] as [$person, $context]) {
            $listed = collect($this->actingAs($person)->withSession(['active_role_assignment_id' => $context])
                ->getJson(route('api.v1.notifications.index'))->assertOk()->json('notifications.data'))
                ->firstWhere('tipo', 'proceso.eliminado');
            $this->assertIsArray($listed, $person->correo_electronico);
            $this->actingAs($person)->withSession(['active_role_assignment_id' => $context])
                ->postJson(route('api.v1.notifications.open', $listed['id']))
                ->assertOk()->assertJsonPath('path', null);
        }
        $this->actingAsTeacher()->postJson(route('api.v1.notifications.open', $earlier->id))->assertOk();
    }

    public function test_the_deletion_message_must_be_text_and_takes_a_thousand_characters_of_any_kind(): void
    {
        $process = $this->openedConvocation()->process()->firstOrFail();

        foreach ([['mensaje' => ['a']], ['mensaje' => 123], ['mensaje' => null], ['mensaje' => "\n\t "]] as $payload) {
            $this->actingAsAdministrator()
                ->deleteJson(route('api.v1.admin.processes.destroy', $process), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['mensaje']);
        }
        $this->assertDatabaseHas('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);

        $text = str_repeat('á', 1000);
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process), ['mensaje' => $text])
            ->assertOk();
        $notice = InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'proceso.eliminado')->sole();
        $this->assertStringEndsWith('Mensaje de Administración: '.$text, $notice->mensaje);
    }

    public function test_administration_must_choose_process_dates_explicitly(): void
    {
        $this->publishedTemplate();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'due_at']);

        $this->assertDatabaseCount('convocatorias_universidad', 0);
    }

    public function test_opening_is_atomic_when_institutional_structure_is_not_ready(): void
    {
        $template = $this->publishedTemplate();
        $this->coordinatorContext->update(['activo' => false]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), $this->processPayload($template))
            ->assertJsonValidationErrors('process');

        $this->assertDatabaseCount('convocatorias_universidad', 0);
        $this->assertDatabaseMissing('eventos_auditoria', [
            'accion' => 'proceso_silabos.creado',
        ]);
    }

    public function test_an_empty_template_cannot_open_an_institutional_process(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Contenedor vacío'])
            ->assertCreated();
        $template = SyllabusTemplate::query()->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), $this->processPayload($template))
            ->assertJsonValidationErrors(['process' => 'La plantilla institucional está vacía. Aplique el formato oficial o configure sus bloques antes de abrir la convocatoria.']);
    }

    public function test_process_dates_start_today_at_the_earliest_and_stay_inside_the_period(): void
    {
        $template = $this->publishedTemplate();
        $period = AcademicPeriod::query()->firstOrFail();
        $period->update(['fecha_fin' => $this->institutionDay(30)]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'starts_at' => $this->institutionDay(-1),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'El inicio no puede ser anterior a hoy.']);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'due_at' => $this->institutionDay(60),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_at');
    }

    public function test_the_due_date_can_be_the_last_class_day_of_the_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00', 'America/Guayaquil'));
        $template = $this->publishedTemplate();
        AcademicPeriod::query()->firstOrFail()->update(['fecha_fin' => '2027-02-26']);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'starts_at' => '2026-11-03',
                'due_at' => '2027-02-27',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at' => 'La fecha no puede pasar del fin del período (26/02/2027).']);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'starts_at' => '2026-11-03',
                'due_at' => '2027-02-26',
            ])
            ->assertSuccessful();

        $this->assertSame('2027-02-26', InstitutionDate::dateOf(SyllabusProcess::query()->firstOrFail()->entrega_en));
    }

    public function test_the_start_can_be_today_even_when_utc_already_moved_to_the_next_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22T00:30:00Z'));
        $template = $this->publishedTemplate();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'nombre' => 'Proceso del día local',
                'starts_at' => '2026-10-21',
                'due_at' => '2026-10-30',
            ])
            ->assertSuccessful();

        $process = SyllabusProcess::query()->firstOrFail();
        $this->assertSame('2026-10-21', InstitutionDate::dateOf($process->inicia_en));
        $this->assertSame('2026-10-30', InstitutionDate::dateOf($process->entrega_en));
    }

    public function test_yesterday_is_rejected_when_utc_already_moved_to_the_next_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22T00:30:00Z'));
        $template = $this->publishedTemplate();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'starts_at' => '2026-10-20',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'El inicio no puede ser anterior a hoy.']);
    }

    public function test_process_dates_arrive_as_days_and_cover_the_whole_deadline_day(): void
    {
        $template = $this->publishedTemplate();
        $start = now()->addDay()->toDateString();
        $due = now()->addMonth()->toDateString();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'nombre' => 'Proceso por días',
                'starts_at' => $start,
                'due_at' => $due,
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $process = SyllabusProcess::query()->firstOrFail();
        $this->assertSame(
            InstitutionDate::startOfDay($start)->toIso8601String(),
            $process->inicia_en->setTimezone(InstitutionDate::timezone())->toIso8601String(),
        );
        $this->assertSame(
            InstitutionDate::endOfDay($due)->toIso8601String(),
            $process->entrega_en->setTimezone(InstitutionDate::timezone())->toIso8601String(),
        );
    }

    public function test_a_process_requires_an_active_institutional_period(): void
    {
        $payload = $this->processPayload($this->publishedTemplate());
        unset($payload['period_id']);

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), $payload)
            ->assertJsonValidationErrors('period_id');
    }

    public function test_coordination_lists_the_institutional_process_before_and_after_starting_its_career(): void
    {
        $process = $this->openProcess();

        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.convocations.index', ['state' => 'sin_iniciar']))
            ->assertOk()->assertJson(fn (Assert $page) => $page->etc()
            ->has('convocations.data', 1)
            ->where('convocations.data.0.process_id', $process->id)
            ->where('convocations.data.0.id', null)
            ->where('convocations.data.0.state', 'sin_iniciar')
            ->where('convocations.data.0.process_state', 'abierto'));

        $this->actingAsCoordinator()->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.coordination.convocations.index'))
            ->assertOk()
            ->assertJsonMissingPath('available_sources');

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $process->id,
        ])
            ->assertSuccessful();
        $convocation = Convocation::query()->where('proceso_id', $process->id)->firstOrFail();

        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.convocations.index', ['state' => 'abierta']))
            ->assertOk()->assertJson(fn (Assert $page) => $page->etc()
            ->has('convocations.data', 1)
            ->where('convocations.data.0.process_id', $process->id)
            ->where('convocations.data.0.id', $convocation->id)
            ->where('convocations.data.0.syllabi_count', 1));
        $this->actingAsCoordinator()->getJson(route('api.v1.coordination.convocations.index', ['state' => 'sin_iniciar']))
            ->assertOk()->assertJson(fn (Assert $page) => $page->etc()->has('convocations.data', 0));
    }

    public function test_dashboards_expose_the_shared_deadline_and_allow_coordination_to_open_its_scope(): void
    {
        $process = $this->openProcess();
        $this->fillSubjectProfiles();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonPath('deadline.state', 'active')
            ->assertJsonPath('deadline.convocation_id', null)
            ->assertJsonPath('coordination_convocation.process_id', $process->id)
            ->assertJsonPath('coordination_convocation.can_open', true)
            ->assertJsonPath('coordination_convocation.state', null)
            ->assertJsonPath('coordination_convocation.process_open', true)
            ->assertJsonMissingPath('coordination_convocation.available_sources')
            ->assertJsonCount(6, 'setup.steps')
            ->assertJsonPath('setup.steps.5.action', 'open_convocation');

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $process->id,
        ])->assertSuccessful();
        $convocation = Convocation::query()->where('proceso_id', $process->id)->firstOrFail();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonPath('deadline.convocation_id', $convocation->id)
            ->assertJsonPath('coordination_convocation.can_open', false)
            ->assertJsonPath('coordination_convocation.convocation_id', $convocation->id);

        $this->actingAsTeacher()
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonCount(0, 'metrics')
            ->assertJsonPath('deadline.convocation_id', $convocation->id)
            ->assertJsonPath('coordination_convocation', null);

        $this->transition($process, 'pausar', 'Se corrige la configuración institucional.')->assertSuccessful();

        $this->actingAsTeacher()
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonPath('deadline.state', 'paused');
    }

    public function test_only_one_process_can_be_in_progress(): void
    {
        $template = $this->publishedTemplate();
        $first = $this->createProcess($template, 'Primero');
        $this->assertSame('abierto', $first->estado);

        $otherPeriod = AcademicPeriod::query()->create([
            'codigo' => 'I-46-ALTERNO',
            'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-05-31',
        ]);
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            ...$this->processPayload($template),
            'period_id' => $otherPeriod->id,
        ])->assertJsonValidationErrors(['process' => 'Finalícela o elimínela antes de abrir otra convocatoria institucional.']);

        $this->transition($first, 'pausar', 'Corrección de la sección de evaluación.')->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            ...$this->processPayload($template),
            'period_id' => $otherPeriod->id,
        ])->assertJsonValidationErrors('process');

        $this->closeProcess($first);
        $second = $this->createProcess($template, 'Segundo', $otherPeriod->id);
        $this->assertSame('abierto', $second->estado);

        $this->closeProcess($second);
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            ...$this->processPayload($template),
            'period_id' => $first->periodo_academico_id,
        ])->assertJsonValidationErrors('period_id');
    }

    public function test_pausing_needs_no_reason_and_is_audited(): void
    {
        $process = $this->openProcess();

        $this->transition($process, 'pausar')->assertSuccessful();

        $this->assertSame('pausado', $process->fresh()->estado);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'proceso_silabos.pausado',
            'recurso_id' => $process->id,
        ]);
    }

    public function test_closing_the_process_closes_its_career_convocations_and_only_admin_can(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();

        $this->actingAsCoordinator()->postJson(route('api.v1.admin.processes.transition', [$process, 'cerrar']))->assertForbidden();
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()->estado);
        $this->assertSame(Convocation::STATE_CLOSED, $convocation->fresh()->estado);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'proceso_silabos.cerrado', 'recurso_id' => $process->id]);
        $this->transition($process, 'cerrar')->assertForbidden();
        $this->transition($process, 'reanudar')->assertForbidden();
    }

    public function test_coordinator_starts_the_institutional_convocation_and_inherits_its_configuration(): void
    {
        $template = $this->publishedTemplate();
        $process = $this->createProcess($template, 'Elaboración 2026-2027');
        $otherPeriod = AcademicPeriod::query()->create([
            'codigo' => 'I-41-ALTERNO',
            'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-05-31',
        ]);

        $this->actingAsCoordinator()->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.coordination.convocations.store'), [
                'process_id' => $process->id,
                'period_id' => $otherPeriod->id,
                'source_ids' => [],
            ])->assertCreated()->assertJsonStructure(['message', 'convocation_id']);

        $convocation = Convocation::query()->firstOrFail();
        $this->assertSame($process->id, $convocation->proceso_id);
        $this->assertSame($process->periodo_academico_id, $convocation->process->periodo_academico_id);
        $this->assertSame('abierta', $convocation->estado);
        $this->getJson(route('api.v1.coordination.convocations.show', $convocation))->assertOk()->assertJsonMissingPath('convocation.sources');
        $this->assertFalse(Schema::hasTable('fuentes_convocatoria'));
        $this->assertFalse(Schema::hasTable('fechas_limite_convocatoria'));

        $this->assertDatabaseCount('silabos', 1);

        $this->expectException(QueryException::class);
        Convocation::query()->create([
            'carrera_id' => $convocation->carrera_id,
            'proceso_id' => $process->id,
            'estado' => Convocation::STATE_PREPARATION,
        ]);
    }

    public function test_an_open_process_freezes_the_template_until_it_is_paused(): void
    {
        $process = $this->openProcess();
        $template = SyllabusTemplate::institutional();
        $sectionPayload = [
            'title' => 'Anexos',
            'key' => 'anexos',
            'first_field_label' => 'Anexo',
            'first_field_key' => 'anexo',
            'first_field_content_type' => 'free_text',
        ];

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.structure.sections.store', $template), $sectionPayload)
            ->assertJsonValidationErrors('process');
        $this->assertSame(12, $template->sections()->count());

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()->where('processLock', 'Proceso activo'));
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.show', $template))
            ->assertSuccessful();

        $this->transition($process, 'pausar', 'Hay que corregir el bloque de bibliografía.')->assertSuccessful();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.structure.sections.store', $template), $sectionPayload)
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertSame(13, $template->fresh()->sections()->count());
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.index'))
            ->assertSuccessful();
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()->where('processLock', null));
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc());
    }

    public function test_changing_the_template_during_a_pause_waits_for_confirmed_process_resume_to_return_syllabi_to_draft(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $template = SyllabusTemplate::institutional();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $otherParallel = $syllabus->parallel->replicate();
        $otherParallel->codigo = 'I188-APROBADO';
        $otherParallel->save();
        $approved = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => $otherParallel->id,
            'contexto_academico' => $syllabus->contexto_academico,
            'estado' => 'aprobado',
        ]);
        $approvedRevision = SyllabusRevision::query()->create([
            'silabo_id' => $approved->id,
            'numero_revision' => 1,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 1,
            'fotografia' => $syllabus->contexto_academico,
            'huella_sha256' => str_repeat('a', 64),
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now(),
        ]);
        Approval::query()->create([
            'revision_silabo_id' => $approvedRevision->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => str_repeat('a', 64),
            'aprobado_por' => $this->coordinator->id,
            'aprobado_en' => now(),
        ]);
        $this->transition($process, 'pausar', 'Ajuste de la plantilla con expedientes abiertos.')->assertSuccessful();
        $sectionPayload = [
            'title' => 'Anexos',
            'key' => 'anexos',
            'first_field_label' => 'Anexo',
            'first_field_key' => 'anexo',
            'first_field_content_type' => 'free_text',
        ];

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.structure.sections.store', $template), $sectionPayload)
            ->assertSuccessful();
        $createdSection = $template->fresh()->sections()->firstWhere('clave', 'anexos');
        $createdField = $this->postJson(route('api.v1.admin.template.structure.fields.store', [$template, $createdSection]), [
            'key' => 'anexo', 'label' => 'Anexo', 'quien_llena' => 'carrera', 'content' => FreeText::fromText('Contenido inicial'),
        ])->assertCreated()->json('field.id');
        $field = FieldDefinition::query()->findOrFail($createdField);
        $value = FieldValue::query()->create([
            'silabo_id' => $syllabus->id,
            'definicion_campo_id' => $field->id,
            'valor' => ['content' => 'Se conserva hasta reanudar.'],
        ]);
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.template.structure.sections.destroy', ['template' => $template, 'section' => $createdSection]))
            ->assertSuccessful();
        $this->assertGreaterThan(
            (float) $process->fresh()->pausado_en->format('U.u'),
            (float) $template->fresh()->plantilla_modificada_en->format('U.u'),
        );
        $this->assertDatabaseCount('silabos', 2);
        $this->assertSame(12, $template->fresh()->sections()->count());
        $this->assertDatabaseHas('valores_campo', ['valor_campo_id' => $value->id]);
        $this->assertDatabaseHas('definiciones_campo', [
            'definicion_campo_id' => $field->id,
            'vigente_en_plantilla' => false,
        ]);
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'borrador']);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.admin.processes.transition', [$process, 'reanudar']), ['confirmar_rehacer' => true])
            ->assertForbidden();
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.transition', [$process, 'reanudar']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'plantilla_cambiada')
            ->assertJsonPath('counts.borrador', 1)
            ->assertJsonPath('counts.aprobado', 1);
        $this->assertSame('pausado', $process->fresh()->estado);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.transition', [$process, 'reanudar']), ['confirmar_rehacer' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'borrador']);
        $this->assertDatabaseHas('silabos', ['silabo_id' => $approved->id, 'estado_silabo' => 'borrador']);
        $this->assertDatabaseMissing('revisiones_silabo', ['revision_silabo_id' => $approvedRevision->id]);
        $this->assertDatabaseMissing('aprobaciones', ['revision_silabo_id' => $approvedRevision->id]);
        $this->assertDatabaseMissing('valores_campo', ['valor_campo_id' => $value->id]);
        $this->assertDatabaseCount('silabos', 2);
        $this->assertFalse(FieldValue::query()->whereIn('silabo_id', [$syllabus->id, $approved->id])
            ->whereNotIn('definicion_campo_id', FieldDefinition::query()->pluck('id'))->exists());
        $this->assertSame('abierto', $process->fresh()->estado);

        $auditEvent = AuditEvent::query()
            ->where('accion', 'proceso.plantilla_rehecho')->sole();
        $this->assertSame($process->id, $auditEvent->recurso_id);
        $this->assertSame($this->administrator->id, $auditEvent->actor_usuario_id);
        $this->assertSame(1, $auditEvent->metadatos['estado_borrador']);
        $this->assertSame(1, $auditEvent->metadatos['estado_aprobado']);

        $deliveredNotice = InternalNotification::query()
            ->where('tipo', 'proceso.plantilla_rehecho')->sole();
        $this->assertSame('proceso_silabos', $deliveredNotice->tipo_recurso);
        $this->assertSame($process->id, $deliveredNotice->recurso_id);
        $this->assertSame('Sus sílabos volvieron a borrador', $deliveredNotice->titulo);
        $this->assertStringContainsString('Se conservó lo que ya había escrito', $deliveredNotice->mensaje);
    }

    public function test_saving_the_whole_template_keeps_each_teacher_answer_that_still_fits_its_field(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $template = SyllabusTemplate::institutional();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $answer = fn (string $key, string $text) => FieldValue::query()->where('silabo_id', $syllabus->id)
            ->where('definicion_campo_id', FieldDefinition::query()->get()->firstWhere('clave', $key)->id)
            ->update(['valor' => FreeText::fromText($text)]);
        $answer('metodologia', 'Aprendizaje basado en proyectos.');
        $answer('tecnicas_ensenanza', 'Debates guiados.');
        $answer('ambientes_aprendizaje', 'Laboratorio de software.');
        $this->transition($process, 'pausar', 'Ajuste de la plantilla con expedientes abiertos.')->assertSuccessful();

        $json = $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.json.export', $template))->assertOk()->json();
        foreach ($json['sections'] as &$section) {
            if ($section['key'] !== 'metodologia') {
                continue;
            }
            $section['fields'] = array_values(array_filter($section['fields'], fn (array $field): bool => $field['key'] !== 'ambientes_aprendizaje'));
            foreach ($section['fields'] as &$field) {
                if ($field['key'] === 'tecnicas_ensenanza') {
                    $field['content'] = ['<p>Indique las técnicas por unidad.</p><p></p>'];
                }
            }
            unset($field);
        }
        unset($section);
        $this->putJson(route('api.v1.admin.template.json.import', $template), $json)->assertOk();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.transition', [$process, 'reanudar']), ['confirmar_rehacer' => true])
            ->assertSuccessful();

        $syllabus->refresh();
        $this->assertSame('borrador', $syllabus->estado);
        $current = FieldDefinition::query()->get()->keyBy('clave');
        $values = $syllabus->values()->get()->keyBy('definicion_campo_id');
        $this->assertFalse($syllabus->values()->whereNotIn('definicion_campo_id', $current->pluck('id'))->exists());
        $this->assertStringContainsString('Aprendizaje basado en proyectos.', json_encode($values[$current['metodologia']->id]->valor, JSON_UNESCAPED_UNICODE));
        $this->assertEquals($current['tecnicas_ensenanza']->contenido, $values[$current['tecnicas_ensenanza']->id]->valor);
        $this->assertArrayNotHasKey('ambientes_aprendizaje', $current->all());
        $profile = app(SubjectProfileFields::class)->values((string) $syllabus->parallel->asignatura_id);
        $this->assertEquals($profile[$current['descripcion']->id], $values[$current['descripcion']->id]->valor);
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk()
            ->assertJsonPath('syllabus.state', 'borrador');
    }

    public function test_changing_the_cycle_count_or_requirements_refreshes_without_purging(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 9,
            ])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertSame(9, data_get($syllabus->fresh()->contexto_academico, 'curriculum.cycle_count'));
        $this->assertSame(9, $career->fresh()->cantidad_ciclos_malla);

        $other = Subject::query()->findOrFail($syllabus->subject->id)->replicate();
        $other->codigo_asignatura = 'SW-699';
        $other->nombre = 'Materia correquisito';
        $other->orden_en_ciclo = 99;
        $other->save();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.curriculum.requirements.store', $career->id), [
                'subject_id' => $syllabus->subject->id,
                'requirement_id' => $other->id,
                'type' => 'correquisito',
            ])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertContains($other->codigo_asignatura, data_get($syllabus->fresh()->contexto_academico, 'subject.corequisites'));
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.curriculum.requirements.store', $career->id), [
                'subject_id' => $syllabus->subject->id,
                'requirement_id' => $other->id,
                'type' => 'prerrequisito',
            ])
            ->assertJsonValidationErrors('requirement_id');
        $this->assertFalse(InternalNotification::query()->where('tipo', 'silabo.regenerado')->exists());
    }

    public function test_cosmetic_curriculum_changes_refresh_the_card_without_purging(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();
        $syllabus = Syllabus::query()->firstOrFail();
        $subject = Subject::query()->findOrFail($syllabus->subject->id);
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();

        $payload = [
            'code' => $subject->codigo_asignatura,
            'nombre' => 'Materia renombrada',
            'cycle' => $subject->ciclo,
            'position' => $subject->orden_en_ciclo,
            'organization_unit' => $subject->unidad_organizacion_curricular?->value ?? 'basica',
            'modality' => $subject->modalidad?->value,
            'creditos' => $subject->creditos,
            'horas_totales' => $subject->horas_totales,
            'horas_ac' => $subject->horas_ac,
            'horas_pae' => $subject->horas_pae,
            'horas_aa' => $subject->horas_aa,
        ];
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.update', ['asignatura', $subject->id]), $payload)
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertSame('Materia renombrada', data_get($syllabus->fresh()->contexto_academico, 'subject.name'));

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.layout.update', $career->id), [
                'subject_id' => $subject->id,
                'cycle' => $subject->ciclo,
                'position' => 7,
            ])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertSame(
            $subject->fresh()->orden_en_ciclo,
            data_get($syllabus->fresh()->contexto_academico, 'subject.position'),
        );

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.update', ['asignatura', $subject->id]), [
                ...$payload,
                'horas_aa' => (float) $subject->horas_aa + 16,
            ])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'sin_iniciar']);
        $this->assertEquals((float) $subject->horas_aa + 16, data_get($syllabus->fresh()->contexto_academico, 'subject.hours_aa'));
    }

    public function test_a_curriculum_change_returns_only_the_affected_sent_syllabus_to_draft_and_keeps_its_content(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();
        $syllabus = Syllabus::query()->firstOrFail();
        $subject = Subject::query()->findOrFail($syllabus->subject->id);
        $bibliography = FieldDefinition::query()->where('clave', 'bibliografia')->firstOrFail();
        FieldValue::query()->where('silabo_id', $syllabus->id)->where('definicion_campo_id', $bibliography->id)
            ->update(['valor' => $this->answeredValue($bibliography, 'Libro elegido por el docente')]);
        Syllabus::query()->whereKey($syllabus->id)->update(['estado' => 'aprobado', 'iniciado_en' => now()]);
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();

        $later = $subject->replicate();
        $later->codigo_asignatura = 'SW-799';
        $later->nombre = 'Materia posterior';
        $later->ciclo = $subject->ciclo + 1;
        $later->save();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.curriculum.requirements.store', $career->id), [
                'subject_id' => $later->id,
                'requirement_id' => $subject->id,
                'type' => 'prerrequisito',
            ])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'aprobado']);

        $payload = [
            'code' => $subject->codigo_asignatura,
            'nombre' => $subject->nombre,
            'cycle' => $subject->ciclo,
            'position' => $subject->orden_en_ciclo,
            'organization_unit' => $subject->unidad_organizacion_curricular?->value ?? 'basica',
            'modality' => $subject->modalidad?->value,
            'creditos' => $subject->creditos,
            'horas_totales' => $subject->horas_totales,
            'horas_ac' => $subject->horas_ac,
            'horas_pae' => $subject->horas_pae,
            'horas_aa' => (float) $subject->horas_aa + 16,
        ];
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.update', ['asignatura', $subject->id]), $payload)
            ->assertJsonValidationErrors('purge_required')
            ->assertJsonPath('errors.purge_count.0', '1');
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'aprobado']);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.update', ['asignatura', $subject->id]), [...$payload, 'confirm_purge' => true])
            ->assertSuccessful();
        $fresh = $syllabus->fresh();
        $this->assertSame('borrador', $fresh->estado);
        $this->assertEquals((float) $subject->horas_aa + 16, data_get($fresh->contexto_academico, 'subject.hours_aa'));
        $this->assertStringContainsString('Libro elegido por el docente', FreeText::plainText($fresh->values()->where('definicion_campo_id', $bibliography->id)->firstOrFail()->valor));
        $this->assertTrue(InternalNotification::query()->where('tipo', 'silabo.devuelto_borrador')->exists());
        $this->assertFalse(InternalNotification::query()->where('tipo', 'silabo.regenerado')->exists());
    }

    public function test_turning_off_syllabus_generation_removes_the_subject_syllabi_and_turning_it_on_creates_them(): void
    {
        $convocation = $this->openedConvocation();
        $syllabus = Syllabus::query()->firstOrFail();
        $subject = Subject::query()->findOrFail($syllabus->subject->id);
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $payload = [
            'code' => $subject->codigo_asignatura,
            'nombre' => $subject->nombre,
            'cycle' => $subject->ciclo,
            'position' => $subject->orden_en_ciclo,
            'organization_unit' => $subject->unidad_organizacion_curricular?->value ?? 'basica',
            'modality' => $subject->modalidad?->value,
            'creditos' => $subject->creditos,
            'horas_totales' => $subject->horas_totales,
            'horas_ac' => $subject->horas_ac,
            'horas_pae' => $subject->horas_pae,
            'horas_aa' => $subject->horas_aa,
        ];
        $update = fn (array $data) => $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.update', ['asignatura', $subject->id]), [...$payload, ...$data]);

        $update(['generates_syllabus' => '0'])->assertJsonValidationErrors('purge_required');
        $this->assertTrue($subject->fresh()->genera_silabo);
        $update(['generates_syllabus' => '0', 'confirm_purge' => true])->assertSuccessful();
        $this->assertFalse($subject->fresh()->genera_silabo);
        $this->assertSame(0, Syllabus::query()->forSubjects([$subject->id])->count());
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.asignatura.actualizacion', 'recurso_id' => $subject->id]);
        $this->assertFalse(collect($this->actingAsCoordinator()->getJson(route('api.v1.coordination.academic.scheduled-subjects.index'))->json('scheduledSubjects'))
            ->firstWhere('subject_id', $subject->id)['generates_syllabus']);

        $update(['generates_syllabus' => '1'])->assertSuccessful();
        $this->assertTrue($subject->fresh()->genera_silabo);
        $this->assertSame(0, Syllabus::query()->forSubjects([$subject->id])->count());
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertSuccessful();
        $this->assertSame(1, Syllabus::query()->forSubjects([$subject->id])->count());
    }

    public function test_a_regeneration_sends_one_notice_per_teacher_naming_every_subject(): void
    {
        $convocation = $this->openedConvocation();
        $mine = Syllabus::query()->whereHas('teachers', fn ($query) => $query->whereKey($this->teacher->id))->firstOrFail();
        $second = Parallel::query()->create(['asignatura_id' => $mine->parallel->asignatura_id, 'codigo' => 'Z', 'jornada' => $mine->parallel->jornada]);
        TeacherAssignment::query()->create([
            'asignacion_rol_id' => $this->teacher->roleAssignments()->where('rol', 'docente')->firstOrFail()->id,
            'paralelo_id' => $second->id,
            'activo' => true,
        ]);
        app(GenerateConvocationSyllabi::class)->generateForParallel($convocation, $second);
        $this->assertSame(2, Syllabus::query()->whereHas('teachers', fn ($query) => $query->whereKey($this->teacher->id))->count());
        $subjects = Syllabus::query()->whereHas('teachers', fn ($query) => $query->whereKey($this->teacher->id))->get()
            ->map(fn (Syllabus $syllabus): string => $syllabus->academicSubjectName())->unique()->values();

        DB::transaction(fn () => app(InProgressWork::class)->regenerate(null, null, 'cambió la malla'));

        $notice = InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'silabo.regenerado')->sole();
        $this->assertStringContainsString('porque cambió la malla', $notice->mensaje);
        foreach ($subjects as $subject) {
            $this->assertStringContainsString($subject, $notice->mensaje);
        }
        $this->assertSame('/docente/mis-silabos', $this->actingAs($this->teacher)
            ->withSession(['active_role_assignment_id' => $this->teacher->roleAssignments()->firstOrFail()->id])
            ->postJson(route('api.v1.notifications.open', $notice))->assertOk()->json('path'));
    }

    public function test_a_purge_never_replaces_an_approved_syllabus(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();
        $approved = Syllabus::query()->firstOrFail();
        Syllabus::query()->whereKey($approved->id)->update(['estado' => 'aprobado']);
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), ['cycle_count' => 9])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $approved->id, 'estado_silabo' => 'aprobado']);
        $this->assertDatabaseCount('silabos', 1);
    }

    public function test_an_institutional_pause_does_not_unlock_an_open_career_convocation(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();
        $this->transition($convocation->process()->firstOrFail(), 'pausar', 'Ajuste institucional del período.')->assertSuccessful();
        $this->assertSame('abierta', $convocation->fresh()->estado);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), ['cycle_count' => 9])
            ->assertJsonValidationErrors('process');
        $this->assertSame(8, $career->fresh()->cantidad_ciclos_malla);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), ['cycle_count' => 9, 'confirm_purge' => 1])
            ->assertSuccessful();
        $this->assertSame(9, $career->fresh()->cantidad_ciclos_malla);
    }

    public function test_a_running_convocation_freezes_curriculum_of_its_career_only(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 9,
            ])
            ->assertJsonValidationErrors('process');
        $this->assertSame(8, $career->fresh()->cantidad_ciclos_malla);

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.curriculum.show', $career))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('curriculum.editable', false)
                ->where('curriculum.lock_reason', 'Convocatoria activa')
                ->where('curriculum.lock.scope', 'convocation')
                ->where('curriculum.lock.institutional_pause', false)
                ->has('curriculum.lock.period')
                ->has('curriculum.lock.delivery_on'));

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertSame('pausada', $convocation->fresh()->estado);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 10,
                'confirm_purge' => 1,
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertSame(10, $career->fresh()->cantidad_ciclos_malla);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertSuccessful();
        $this->assertSame('abierta', $convocation->fresh()->estado);
    }

    public function test_changing_a_subject_profile_returns_its_approved_syllabus_to_draft_after_confirmation(): void
    {
        $convocation = $this->openedConvocation();
        $syllabus = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();
        $subjectId = $syllabus->subject->id;
        $field = $this->careerProjectFields()->firstOrFail();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))->assertSuccessful();
        Syllabus::query()->whereKey($syllabus->id)->update(['estado' => 'aprobado']);

        $this->actingAsCoordinator()
            ->putJson(route('api.v1.academic.subjects.profile.update', $subjectId), ['values' => []])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'aprobado']);

        $values = ['values' => [$field->id => $this->answeredValue($field, 'Ficha corregida por Coordinación')]];
        $this->actingAsCoordinator()
            ->putJson(route('api.v1.academic.subjects.profile.update', $subjectId), $values)
            ->assertJsonValidationErrors('purge_required');
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'aprobado']);

        $this->actingAsCoordinator()
            ->putJson(route('api.v1.academic.subjects.profile.update', $subjectId), [...$values, 'confirm_purge' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'borrador']);
        $this->assertStringContainsString('Ficha corregida por Coordinación', FreeText::plainText($syllabus->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor));
    }

    public function test_an_open_cycle_freezes_the_template_but_not_faculties_careers_or_parallels(): void
    {
        $convocation = $this->openedConvocation();
        $scheduledSubject = Parallel::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'subject_id' => $scheduledSubject->asignatura_id,
                'codes' => ['B'],
                'shift' => 'matutina',
                'user_id' => $this->teacher->id,
            ])
            ->assertSuccessful();
        $created = Parallel::query()->where('asignatura_id', $scheduledSubject->asignatura_id)->where('codigo_paralelo', 'B')->firstOrFail();
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $created), ['code' => 'C', 'shift' => 'nocturna'])
            ->assertSuccessful();
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.destroy', ['asignacion_docente', $created->teacherAssignments()->where('activo', true)->sole()->id]))
            ->assertSuccessful();
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $created))
            ->assertSuccessful();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'facultad'), [
                'nombre' => 'Facultad creada durante el proceso',
                'campus' => CampusLocation::Laguacoto->value,
                'logo' => $this->transparentPng(600, 180),
            ])
            ->assertCreated();

        $faculty = Faculty::query()->findOrFail($scheduledSubject->subject->career->facultad_id);
        $syllabusId = Syllabus::query()->firstOrFail()->id;
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['facultad', $faculty->id]), [
                'nombre' => 'Facultad renombrada',
                'campus' => CampusLocation::Chimbo->value,
            ])
            ->assertSuccessful();
        $this->assertSame(CampusLocation::Chimbo, $faculty->fresh()->campus);

        $this->assertSame(CampusLocation::Chimbo->value, $scheduledSubject->fresh()->campus_programacion);
        $syllabus = Syllabus::query()->findOrFail($syllabusId);
        $this->assertSame('Facultad renombrada', data_get($syllabus->contexto_academico, 'career.faculty'));
        $this->assertSame(
            $scheduledSubject->fresh()->campus_programacion,
            data_get($syllabus->contexto_academico, 'scheduled_subject.campus'),
        );

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'subject_id' => $scheduledSubject->asignatura_id,
                'codes' => ['B'],
                'shift' => 'matutina',
                'user_id' => $this->teacher->id,
            ])
            ->assertSuccessful()
            ->assertSuccessful();

    }

    public function test_teachers_stop_working_while_the_career_or_the_institution_is_paused(): void
    {
        $convocation = $this->openedConvocation();
        $syllabus = Syllabus::query()->firstOrFail();

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $this->assertSame('borrador', $syllabus->fresh()->estado);
        $editableField = $this->teacherFields()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $editableField]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => 'No debe guardarse durante una pausa.',
        ])->assertForbidden();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))->assertSuccessful();
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();

        $process = $convocation->process()->firstOrFail();
        $this->transition($process, 'pausar', 'Ajuste institucional de la plantilla.')->assertSuccessful();
        $this->assertSame('abierta', $convocation->fresh()->estado);
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $editableField]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => 'Tampoco debe guardarse durante la pausa institucional.',
        ])->assertForbidden();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertJsonValidationErrors('convocation');

        $this->transition($process, 'reanudar')->assertSuccessful();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))->assertSuccessful();
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();
    }

    public function test_coordinator_cannot_edit_a_convocation_and_only_administration_closes_the_process(): void
    {
        $convocation = $this->openedConvocation();
        $payload = ['process_id' => $convocation->proceso_id];

        $this->actingAsCoordinator()->patch('/coordinacion/convocatorias/'.$convocation->id, $payload)->assertNotFound();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $this->actingAsCoordinator()->patch('/coordinacion/convocatorias/'.$convocation->id, $payload)->assertNotFound();
        $this->assertSame($convocation->career->nombre.' · '.$convocation->process->academicPeriod->codigo, $convocation->fresh()->nombre);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'cerrar']))->assertNotFound();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson('/api/v1/admin/processes/'.$convocation->process()->firstOrFail()->id.'/cerrar')->assertSuccessful();
        $this->assertSame('cerrada', $convocation->fresh()->estado);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertForbidden();
    }

    private function submitSyllabus(Syllabus $syllabus): void
    {
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $fields = $this->teacherFields();
        foreach ($fields as $field) {
            $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
                'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
                'value' => $this->answeredValue($field, 'Contenido académico de prueba'),
            ])->assertOk();
        }
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
    }

    private function storedProcessWork(): string
    {
        $tables = ['convocatorias_universidad', 'convocatorias_carreras', 'silabos', 'revisiones_silabo', 'firmas_silabo', 'valores_campo', 'notificaciones', 'aprobaciones', 'exportaciones_silabo', 'envios_biblioteca'];

        return md5((string) json_encode(collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->get()->map(fn (object $row): string => (string) json_encode((array) $row))->sort()->values()->all(),
        ])->all()));
    }

    private function closeProcess(SyllabusProcess $process): void
    {
        $process->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        Convocation::query()->where('proceso_id', $process->id)->update(['estado' => Convocation::STATE_CLOSED]);
    }

    /**
     * @return array{period_id: string, starts_at: string, due_at: string}
     */
    private function processPayload(SyllabusTemplate $template): array
    {
        return [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => now()->toIso8601String(),
            'due_at' => now()->addMonth()->toIso8601String(),
        ];
    }

    private function createProcess(SyllabusTemplate $template, string $name, ?string $periodId = null): SyllabusProcess
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [...$this->processPayload($template), 'nombre' => $name, ...($periodId === null ? [] : ['period_id' => $periodId])])
            ->assertSuccessful();

        return SyllabusProcess::query()->where('periodo_academico_id', $periodId ?? $this->processPayload($template)['period_id'])->firstOrFail();
    }

    private function openProcess(): SyllabusProcess
    {
        return $this->createProcess($this->publishedTemplate(), 'Proceso abierto')->fresh();
    }

    private function openedConvocation(): Convocation
    {
        $process = $this->openProcess();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $process->id,
        ])->assertSuccessful();
        $convocation = Convocation::query()->where('proceso_id', $process->id)->firstOrFail();

        return $convocation->fresh();
    }

    private function transition(SyllabusProcess $process, string $transition): TestResponse
    {
        return $this->actingAsAdministrator()->post(route('api.v1.admin.processes.transition', [$process, $transition]));
    }

    private function publishedTemplate(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-31']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();

        return $template->fresh();
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
}
