<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        foreach (['creado_por', 'abierto_por', 'abierto_en', 'pausado_en', 'cerrado_en', 'actualizado_en'] as $column) {
            $this->assertFalse(Schema::hasColumn('convocatorias_universidad', $column));
        }

        $this->assertTrue(Schema::hasColumns('convocatorias_carreras', ['id', 'carrera_id', 'convocatoria_universidad_id', 'estado_convocatoria_carrera']));
        $this->assertTrue(Schema::hasColumns('convocatorias_universidad', ['id', 'periodo_academico_id', 'inicia_en', 'entrega_en', 'estado_convocatoria_universidad']));
        foreach (['silabos', 'convocatorias_universidad', 'artefactos_exportacion', 'mensajes_ia'] as $table) {
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
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))
            ->assertOk()
            ->assertJsonPath('processes.0.deletable', true);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertOk()
            ->assertJsonPath('message', 'Convocatoria institucional eliminada.');

        $this->assertDatabaseMissing('convocatorias_universidad', ['id' => $process->id]);
        $this->assertDatabaseMissing('convocatorias_carreras', ['id' => $convocation->id]);
        foreach ($syllabusIds as $syllabusId) {
            $this->assertDatabaseMissing('silabos', ['id' => $syllabusId]);
        }
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'proceso_silabos.eliminado',
            'recurso_id' => $process->id,
        ]);
    }

    public function test_a_process_is_never_deletable_after_a_teacher_started_a_syllabus(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $syllabus = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();

        $this->actingAsTeacher()
            ->postJson(route('api.v1.teacher.syllabi.start', $syllabus))
            ->assertSuccessful();

        $syllabus->update([
            'estado' => 'sin_iniciar',
            'version_bloqueo' => 0,
            'iniciado_en' => null,
            'guardado_en' => null,
        ]);
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))
            ->assertOk()
            ->assertJsonPath('processes.0.deletable', false);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'process' => 'No se puede eliminar porque al menos un docente ya inició un sílabo. El historial académico debe conservarse.',
            ]);

        $this->assertDatabaseHas('convocatorias_universidad', ['id' => $process->id]);
        $this->assertDatabaseHas('silabos', ['id' => $syllabus->id]);
    }

    public function test_coordination_cannot_delete_an_institutional_process(): void
    {
        $process = $this->createProcess($this->publishedTemplate(), 'Proceso protegido');

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.admin.processes.destroy', $process))
            ->assertForbidden();

        $this->assertDatabaseHas('convocatorias_universidad', ['id' => $process->id]);
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
        $source = $this->coordinatorSource();

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
        $source = $this->coordinatorSource();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonPath('deadline.state', 'active')
            ->assertJsonPath('deadline.convocation_id', null)
            ->assertJsonPath('coordination_convocation.process_id', $process->id)
            ->assertJsonPath('coordination_convocation.can_open', true)
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
            ->assertJsonCount(2, 'metrics')
            ->assertJsonPath('deadline.state', 'active')
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
        ])->assertJsonValidationErrors('process');

        $this->transition($first, 'pausar', 'Corrección de la sección de evaluación.')->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            ...$this->processPayload($template),
            'period_id' => $otherPeriod->id,
        ])->assertJsonValidationErrors('process');

        $this->transition($first, 'cerrar')->assertSuccessful();
        $second = $this->createProcess($template, 'Segundo', $otherPeriod->id);
        $this->assertSame('abierto', $second->estado);

        $this->transition($second, 'cerrar')->assertSuccessful();
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
        $this->coordinatorSource();
        AcademicSource::query()->create([
            'carrera_id' => $this->coordinatorContext->carrera_id,
            'nombre' => 'Fuente activa no elegida',
            'descripcion' => 'No debe incorporarse implícitamente.',
            'contenido' => 'Contenido fuera de la selección de esta convocatoria.',
            'activo' => true,
        ]);
        AcademicSource::query()->create([
            'carrera_id' => $this->coordinatorContext->carrera_id,
            'nombre' => 'Fuente inactiva',
            'descripcion' => 'No puede incorporarse a una convocatoria nueva.',
            'contenido' => 'Contenido inactivo.',
            'activo' => false,
        ]);
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
        $this->assertDatabaseCount('fuentes_academicas', 3);
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

    public function test_changing_the_template_during_a_pause_replaces_the_affected_syllabi(): void
    {
        $convocation = $this->openedConvocation();
        $process = $convocation->process()->firstOrFail();
        $template = SyllabusTemplate::institutional();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $this->transition($process, 'pausar', 'Ajuste de la plantilla con expedientes abiertos.')->assertSuccessful();
        $sectionPayload = [
            'title' => 'Anexos',
            'key' => 'anexos',
            'first_field_label' => 'Anexo',
            'first_field_key' => 'anexo',
            'first_field_content_type' => 'free_text',
        ];

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.sections.store', $template), $sectionPayload)
            ->assertJsonValidationErrors(['purge_required', 'purge_count']);
        $this->assertDatabaseCount('silabos', 1);
        $this->assertSame(12, $template->sections()->count());

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $template), [...$sectionPayload, 'confirm_purge' => 1])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertDatabaseMissing('silabos', ['id' => $syllabus->id]);
        $this->assertDatabaseCount('silabos', 1);
        $this->assertDatabaseHas('silabos', ['estado_silabo' => 'sin_iniciar']);
        $this->assertSame(13, $template->fresh()->sections()->count());

        $notice = JobExecution::query()
            ->where('tipo', 'notificacion.interna')
            ->whereJsonContains('datos_entrada', ['tipo_evento' => 'silabo.regenerado'])
            ->sole();
        $this->assertSame('carrera', $notice->tipo_recurso);
        $this->assertSame($convocation->carrera_id, $notice->recurso_id);
        $this->assertNotEmpty($notice->entrada['destinatarios_ids']);
        $this->assertStringContainsString($syllabus->academicSubjectName(), $notice->entrada['mensaje']);
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
        $this->assertDatabaseHas('silabos', ['id' => $syllabus->id]);
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
        $this->assertDatabaseHas('silabos', ['id' => $syllabus->id]);
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
        $this->assertDatabaseHas('silabos', ['id' => $syllabus->id]);
        $this->assertSame('Materia renombrada', data_get($syllabus->fresh()->contexto_academico, 'subject.name'));

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.layout.update', $career->id), [
                'subject_id' => $subject->id,
                'cycle' => $subject->ciclo,
                'position' => 7,
            ])
            ->assertSuccessful();
        $this->assertDatabaseHas('silabos', ['id' => $syllabus->id]);
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
        $this->assertDatabaseHas('silabos', ['id' => $approved->id, 'estado_silabo' => 'aprobado']);
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
                ->where('curriculum.lock_reason', 'Convocatoria activa'));

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

    public function test_an_open_cycle_only_freezes_the_template_and_the_active_careers_structure(): void
    {
        $convocation = $this->openedConvocation();
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'scheduled_subject_id' => $scheduledSubject->id,
                'codes' => ['B'],
                'shift' => 'matutina',
            ])
            ->assertJsonValidationErrors('process');
        $parallel = $scheduledSubject->parallels()->firstOrFail();
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
                'scheduled_subject_id' => $scheduledSubject->id,
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
        $source = AcademicSource::query()->firstOrFail();
        $payload = ['process_id' => $convocation->proceso_id];

        $this->actingAsCoordinator()->patch('/coordinacion/convocatorias/'.$convocation->id, $payload)->assertNotFound();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        $this->actingAsCoordinator()->patch('/coordinacion/convocatorias/'.$convocation->id, $payload)->assertNotFound();
        $this->assertSame($convocation->career->nombre.' · '.$convocation->process->academicPeriod->codigo, $convocation->fresh()->nombre);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'cerrar']))->assertNotFound();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->transition($convocation->process()->firstOrFail(), 'cerrar')->assertSuccessful();
        $this->assertSame('pausada', $convocation->fresh()->estado);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertForbidden();
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
        $source = $this->coordinatorSource();

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

        return $template->fresh();
    }

    private function coordinatorSource(): AcademicSource
    {
        $source = AcademicSource::query()->create([
            'carrera_id' => $this->coordinatorContext->carrera_id, 'nombre' => 'Fuente I-31',
            'activo' => true, 'contenido' => 'Evidencia histórica conservada.',
        ]);

        return $source->fresh();
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
