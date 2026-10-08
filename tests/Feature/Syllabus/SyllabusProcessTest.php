<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Jobs\DeliverInternalNotificationJob;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\TestCase;

class SyllabusProcessTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;

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
        foreach (['silabos', 'convocatorias_universidad', 'exportaciones_silabo', 'mensajes_ia'] as $table) {
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

    public function test_opening_the_institutional_process_carries_parallels_and_teachers_from_the_previous_period(): void
    {
        $template = $this->publishedTemplate();
        $period = AcademicPeriod::query()->firstOrFail();
        $existing = Subject::query()->where('carrera_id', $this->coordinatorContext->carrera_id)->firstOrFail();
        $subject = Subject::query()->create([
            'carrera_id' => $existing->carrera_id,
            'codigo_asignatura' => 'SW-ARRASTRE',
            'nombre' => 'Materia que se arrastra',
            'ciclo' => $existing->ciclo,
            'orden_en_ciclo' => 99,
            'activo' => true,
        ]);
        $previous = AcademicPeriod::query()->create([
            'codigo' => 'ANTERIOR',
            'fecha_inicio' => $period->fecha_inicio->subMonths(8),
            'fecha_fin' => $period->fecha_inicio->subMonths(3),
            'semanas_lectivas' => 16,
        ]);
        $source = Parallel::query()->create(['periodo_academico_id' => $previous->id, 'asignatura_id' => $subject->id, 'codigo' => 'B', 'jornada' => 'nocturna']);
        $source->teacherAssignments()->create(['asignacion_rol_id' => $this->teacherContext->id, 'activo' => true]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), $this->processPayload($template))
            ->assertSuccessful();

        $copy = Parallel::query()->where('periodo_academico_id', $period->id)->where('asignatura_id', $subject->id)->firstOrFail();
        $this->assertSame('B', $copy->codigo);
        $this->assertSame('nocturna', $copy->jornada);
        $this->assertSame($this->teacherContext->id, $copy->teacherAssignments()->where('activo', true)->value('asignacion_rol_id'));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.periodo_arrastrado', 'recurso_id' => $period->id]);
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

    public function test_administration_can_delete_a_process_without_teacher_activity(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $syllabusIds = Syllabus::query()->where('convocatoria_id', $convocation->id)->pluck('id');
        $this->assertNotEmpty($syllabusIds);
        $this->closeProcess($process);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertOk()
            ->assertJsonPath('message', 'Convocatoria institucional eliminada.');

        $this->assertDatabaseMissing('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        $this->assertDatabaseMissing('convocatorias_carreras', ['convocatoria_carrera_id' => $convocation->id]);
        foreach ($syllabusIds as $syllabusId) {
            $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabusId]);
        }
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'proceso_silabos.eliminado',
            'recurso_id' => $process->id,
        ]);
    }

    public function test_administration_deletes_a_process_even_after_a_teacher_started_a_syllabus(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $syllabus = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();

        $this->actingAsTeacher()
            ->postJson(route('api.v1.teacher.syllabi.start', $syllabus))
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id, 'estado_silabo' => 'borrador']);

        // I-172: la convocatoria se elimina con todo su historial, sin importar la actividad docente.
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertOk();

        $this->assertDatabaseMissing('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'proceso_silabos.eliminado',
            'recurso_id' => $process->id,
        ]);
    }

    public function test_coordination_cannot_delete_an_institutional_process(): void
    {
        $process = $this->createProcess($this->publishedTemplate(), 'Proceso protegido');

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertForbidden();

        $this->assertDatabaseHas('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
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
        $period->update(['fecha_fin' => now()->addMonth()->toDateString()]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'starts_at' => now()->subDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'El inicio no puede ser anterior a hoy.']);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.processes.store'), [
                ...$this->processPayload($template),
                'due_at' => now()->addMonths(2)->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_at');
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
        $this->assertSame("{$start} 00:00:00", $process->inicia_en->format('Y-m-d H:i:s'));
        $this->assertSame("{$due} 23:59:59", $process->entrega_en->format('Y-m-d H:i:s'));
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
            ->assertJsonCount(5, 'setup.steps')
            ->assertJsonPath('setup.steps.4.action', 'open_convocation');

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
            ->assertJsonPath('deadline', null)
            ->assertJsonPath('coordination_convocation', null);

        $this->transition($process, 'pausar', 'Se corrige la configuración institucional.')->assertSuccessful();

        $this->actingAsTeacher()
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonPath('deadline', null);
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
        ])->assertJsonValidationErrors('process');

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

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.sections.store', $template), $sectionPayload)
            ->assertJsonValidationErrors('process');
        $this->assertSame(12, $template->sections()->count());

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.show', $template))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()->where('processLock', 'Proceso activo'));
        $this->actingAsAdministrator()->getJson(route('api.v1.admin.template.show', $template))
            ->assertSuccessful();

        $this->transition($process, 'pausar', 'Hay que corregir el bloque de bibliografía.')->assertSuccessful();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.sections.store', $template), $sectionPayload)
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

    public function test_changing_the_template_during_a_pause_waits_for_confirmed_process_resume_to_replace_syllabi(): void
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

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.sections.store', $template), $sectionPayload)
            ->assertSuccessful();
        $createdSection = $template->fresh()->sections()->firstWhere('clave', 'anexos');
        $field = $createdSection->blocks()->firstOrFail()->fields()->firstOrFail();
        $value = FieldValue::query()->create([
            'silabo_id' => $syllabus->id,
            'definicion_campo_id' => $field->id,
            'valor' => ['content' => 'Se conserva hasta reanudar.'],
        ]);
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.template.sections.destroy', ['template' => $template, 'section' => $createdSection]))
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
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $approved->id]);
        $this->assertDatabaseMissing('revisiones_silabo', ['revision_silabo_id' => $approvedRevision->id]);
        $this->assertDatabaseMissing('aprobaciones', ['revision_silabo_id' => $approvedRevision->id]);
        $this->assertDatabaseMissing('valores_campo', ['valor_campo_id' => $value->id]);
        $this->assertDatabaseCount('silabos', 2);
        $this->assertSame(2, Syllabus::query()->where('estado', 'sin_iniciar')->count());
        $this->assertSame('abierto', $process->fresh()->estado);

        $auditEvent = AuditEvent::query()
            ->where('accion', 'proceso.plantilla_rehecho')->sole();
        $this->assertSame($process->id, $auditEvent->recurso_id);
        $this->assertSame($this->administrator->id, $auditEvent->actor_usuario_id);
        $this->assertSame(1, $auditEvent->metadatos['estado_borrador']);
        $this->assertSame(1, $auditEvent->metadatos['estado_aprobado']);

        $notice = JobExecution::query()
            ->where('tipo', 'notificacion.interna')
            ->whereJsonContains('datos_entrada', ['tipo_evento' => 'proceso.plantilla_rehecho'])
            ->sole();
        $this->assertSame('proceso_silabos', $notice->tipo_recurso);
        $this->assertSame($process->id, $notice->recurso_id);
        $this->assertNotEmpty($notice->entrada['destinatarios_ids']);
        (new DeliverInternalNotificationJob($notice->id))->handle();
        $deliveredNotice = InternalNotification::query()
            ->where('tipo', 'proceso.plantilla_rehecho')->sole();
        $this->assertStringContainsString('se rehízo desde cero', $deliveredNotice->mensaje);
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
        $this->assertFalse(JobExecution::query()
            ->whereJsonContains('datos_entrada', ['tipo_evento' => 'silabo.regenerado'])->exists());
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
            ->assertJsonValidationErrors('purge_required');
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

    public function test_changing_a_subject_profile_restarts_only_that_subjects_syllabi_after_confirmation(): void
    {
        $convocation = $this->openedConvocation();
        $syllabus = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();
        $subjectId = $syllabus->subject->id;
        $others = Syllabus::query()->where('convocatoria_id', $convocation->id)->whereKeyNot($syllabus->id)->pluck('id')->all();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))->assertSuccessful();

        $this->actingAsCoordinator()
            ->putJson(route('api.v1.academic.subjects.profile.update', $subjectId), ['values' => []])
            ->assertJsonValidationErrors('purge_required');
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);

        $this->actingAsCoordinator()
            ->putJson(route('api.v1.academic.subjects.profile.update', $subjectId), ['values' => [], 'confirm_purge' => true])
            ->assertSuccessful();
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        foreach ($others as $id) {
            $syllabusOfOther = Syllabus::query()->find($id);
            if ($syllabusOfOther !== null && $syllabusOfOther->subject->id !== $subjectId) {
                $this->assertDatabaseHas('silabos', ['silabo_id' => $id]);
            }
        }
    }

    public function test_an_open_cycle_only_freezes_the_template_and_the_active_careers_structure(): void
    {
        $convocation = $this->openedConvocation();
        $scheduledSubject = Parallel::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'subject_id' => $scheduledSubject->asignatura_id,
                'period_id' => $scheduledSubject->periodo_academico_id,
                'codes' => ['B'],
                'shift' => 'matutina',
            ])
            ->assertJsonValidationErrors('process');
        $parallel = $scheduledSubject;
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $parallel), ['code' => 'Z', 'shift' => 'nocturna'])
            ->assertJsonValidationErrors('process');
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $parallel))
            ->assertJsonValidationErrors('process');

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'facultad'), [
                'nombre' => 'Facultad creada durante el proceso',
                'campus' => CampusLocation::Laguacoto->value,
                'logo' => $this->transparentPng(600, 180),
            ])
            ->assertCreated();

        $faculty = Faculty::query()->findOrFail($scheduledSubject->subject->career->facultad_id);
        $originalScheduledCampus = $scheduledSubject->campus_programacion;
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['facultad', $faculty->id]), [
                'nombre' => $faculty->nombre,
                'campus' => CampusLocation::Chimbo->value,
            ])
            ->assertJsonValidationErrors('process');
        $this->assertSame($originalScheduledCampus, $scheduledSubject->fresh()->campus_programacion);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $syllabusId = Syllabus::query()->firstOrFail()->id;
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['facultad', $faculty->id]), [
                'nombre' => 'Facultad renombrada',
                'campus' => CampusLocation::Chimbo->value,
            ])
            ->assertSuccessful();
        $this->assertSame(CampusLocation::Chimbo, $faculty->fresh()->campus);
        // El campus de la programación se deriva de la facultad: cambia con ella.
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
                'period_id' => $scheduledSubject->periodo_academico_id,
                'codes' => ['B'],
                'shift' => 'matutina',
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
        $editableField = FieldDefinition::query()
            ->where('editable_docente', true)
            ->firstOrFail();

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
        $this->actingAsAdministrator()->postJson('/api/v1/admin/processes/'.$convocation->process()->firstOrFail()->id.'/cerrar')->assertNotFound();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->closeProcess($convocation->process()->firstOrFail());
        $this->assertSame('cerrada', $convocation->fresh()->estado);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertForbidden();
    }

    private function closeProcess(SyllabusProcess $process): void
    {
        $process->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        Convocation::query()->where('proceso_id', $process->id)->update(['estado' => Convocation::STATE_CLOSED]);
    }

    /** @return array{period_id: string, starts_at: string, due_at: string} */
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
