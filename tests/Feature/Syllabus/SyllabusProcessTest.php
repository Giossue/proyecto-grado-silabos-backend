<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
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

/**
 * I-31: el calendario académico oficial obliga a toda la universidad. Administración abre
 * el proceso; Coordinación convoca a su carrera dentro de él; la pausa —institucional o
 * de carrera— es lo que permite corregir plantilla, malla y fuentes sin que el trabajo
 * docente siga avanzando sobre una base que está cambiando.
 */
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

        $this->assertTrue(Schema::hasColumns('convocatorias_carreras', ['id', 'carrera_id', 'proceso_id', 'estado_convocatoria_carrera']));
        $this->assertTrue(Schema::hasColumns('convocatorias_universidad', ['id', 'periodo_academico_id', 'plantilla_id', 'inicia_en', 'entrega_en', 'estado_convocatoria_universidad']));
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
        $this->assertDatabaseHas('eventos_auditoria', ['accion_evento_auditoria' => 'proceso_silabos.creado', 'recurso_id' => $process->id]);

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
            'accion_evento_auditoria' => 'proceso_silabos.creado',
            'recurso_id' => $process->id,
        ]);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'proceso_silabos.abierto',
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
        $this->assertDatabaseCount('alcances_silabo', 0);
        $this->assertDatabaseCount('colaboradores_silabo', 0);
        $this->assertDatabaseCount('fechas_limite_convocatoria', 0);
        $this->assertDatabaseCount('fuentes_convocatoria', 0);
        foreach ($syllabusIds as $syllabusId) {
            $this->assertDatabaseMissing('silabos', ['id' => $syllabusId]);
        }
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'proceso_silabos.eliminado',
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

        // Aunque un reinicio devuelva el expediente al estado inicial, el evento
        // inmutable de inicio conserva la actividad docente y protege el proceso.
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
            'accion_evento_auditoria' => 'proceso_silabos.creado',
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
            ->assertJsonPath('available_sources.0.id', $source->id)
            ->assertJsonPath('available_sources.0.name', $source->nombre);

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $process->id,
            'source_ids' => [$source->id],
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
            ->assertJsonPath('coordination_convocation.available_sources.0.id', $source->id)
            ->assertJsonPath('setup.steps.5.action', 'open_convocation');

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $process->id,
            'source_ids' => [$source->id],
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

        // Mientras una convocatoria esté abierta no se puede crear otra, ni siquiera
        // para un período diferente.
        $otherPeriod = AcademicPeriod::query()->create([
            'codigo' => 'I-46-ALTERNO',
            'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-05-31',
        ]);
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            ...$this->processPayload($template),
            'period_id' => $otherPeriod->id,
        ])->assertJsonValidationErrors('process');

        // En pausa sigue ocupando el lugar: pausar no es cerrar.
        $this->transition($first, 'pausar', 'Corrección de la sección de evaluación.')->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            ...$this->processPayload($template),
            'period_id' => $otherPeriod->id,
        ])->assertJsonValidationErrors('process');

        $this->transition($first, 'cerrar')->assertSuccessful();
        $second = $this->createProcess($template, 'Segundo', $otherPeriod->id);
        $this->assertSame('abierto', $second->estado);

        // Cada período conserva una sola convocatoria, aunque la anterior ya cerró.
        $this->transition($second, 'cerrar')->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            ...$this->processPayload($template),
            'period_id' => $first->periodo_academico_id,
        ])->assertJsonValidationErrors('period_id');
    }

    public function test_pausing_requires_a_written_reason_and_is_audited(): void
    {
        $process = $this->openProcess();

        $this->transition($process, 'pausar', null)->assertJsonValidationErrors('reason');
        $this->transition($process, 'pausar', 'Corto')->assertJsonValidationErrors('reason');
        $this->transition($process, 'pausar', 'Ajuste de la plantilla tras la observación del vicerrectorado.')
            ->assertSuccessful();

        $this->assertSame('pausado', $process->fresh()->estado);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion_evento_auditoria' => 'proceso_silabos.pausado',
            'recurso_id' => $process->id,
        ]);
    }

    public function test_coordinator_starts_the_institutional_convocation_and_inherits_its_configuration(): void
    {
        $template = $this->publishedTemplate();
        $process = $this->createProcess($template, 'Elaboración 2026-2027');
        $source = $this->coordinatorSource();
        $unselectedSource = AcademicSource::query()->create([
            'carrera_id' => $this->coordinatorContext->carrera_id,
            'nombre' => 'Fuente activa no elegida',
            'descripcion' => 'No debe incorporarse implícitamente.',
            'contenido' => 'Contenido fuera de la selección de esta convocatoria.',
            'activo' => true,
        ]);
        $inactiveSource = AcademicSource::query()->create([
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
                'source_ids' => [$inactiveSource->id],
            ])->assertUnprocessable()->assertJsonValidationErrors('source_ids');

        $this->actingAsCoordinator()->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.coordination.convocations.store'), [
                // El cliente no puede sustituir el período; sí elige las fuentes de su carrera.
                'process_id' => $process->id,
                'period_id' => $otherPeriod->id,
                'source_ids' => [$source->id],
            ])->assertCreated()->assertJsonStructure(['message', 'convocation_id']);

        $convocation = Convocation::query()->firstOrFail();
        $this->assertSame($process->id, $convocation->proceso_id);
        $this->assertSame($process->periodo_academico_id, $convocation->process->periodo_academico_id);
        $this->assertSame($template->id, $convocation->process->plantilla_id);
        $this->assertSame('abierta', $convocation->estado);
        $this->assertTrue($convocation->sources()->whereKey($source->id)->exists());
        $this->assertFalse($convocation->sources()->whereKey($unselectedSource->id)->exists());
        $this->assertDatabaseHas('fechas_limite_convocatoria', [
            'convocatoria_id' => $convocation->id,
            'etapa_fecha_limite_convocatoria' => 'inicio',
            'vence_en' => $process->inicia_en,
        ]);
        $this->assertDatabaseHas('fechas_limite_convocatoria', [
            'convocatoria_id' => $convocation->id,
            'etapa_fecha_limite_convocatoria' => 'borrador',
            'vence_en' => $process->entrega_en,
        ]);

        $this->assertDatabaseCount('silabos', 1);

        // La unicidad también vive en PostgreSQL, por si una mutación evita el caso de uso.
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
        $template = $process->template;
        $sectionPayload = [
            'title' => 'Anexos',
            'key' => 'anexos',
            'first_field_label' => 'Anexo',
            'first_field_key' => 'anexo',
            'first_field_content_type' => 'text',
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
        $template = $process->template;
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        $this->transition($process, 'pausar', 'Ajuste de la plantilla con expedientes abiertos.')->assertSuccessful();
        $sectionPayload = [
            'title' => 'Anexos',
            'key' => 'anexos',
            'first_field_label' => 'Anexo',
            'first_field_key' => 'anexo',
            'first_field_content_type' => 'text',
        ];

        // Sin confirmar: nada cambia y el servidor devuelve la cifra.
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.sections.store', $template), $sectionPayload)
            ->assertJsonValidationErrors(['purge_required', 'purge_count']);
        $this->assertDatabaseCount('silabos', 1);
        $this->assertSame(12, $template->sections()->count());

        // Confirmado: el expediente anterior se reemplaza y el cambio se aplica.
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.sections.store', $template), [...$sectionPayload, 'confirm_purge' => 1])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertDatabaseMissing('silabos', ['id' => $syllabus->id]);
        $this->assertDatabaseCount('silabos', 1);
        $this->assertDatabaseHas('silabos', ['estado_silabo' => 'sin_iniciar']);
        $this->assertSame(13, $template->fresh()->sections()->count());
    }

    public function test_changing_the_career_structure_during_a_pause_regenerates_the_affected_syllabi(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), ['reason' => 'Corrección de la malla con expedientes abiertos.'])
            ->assertSuccessful();

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 9,
            ])
            ->assertJsonValidationErrors('purge_required');
        $this->assertDatabaseCount('silabos', 1);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 9,
                'confirm_purge' => 1,
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertDatabaseCount('silabos', 1);
        $this->assertSame(9, data_get(Syllabus::query()->firstOrFail()->contexto_academico, 'curriculum.cycle_count'));
        $this->assertSame(9, $career->fresh()->cantidad_ciclos_malla);
    }

    public function test_a_running_convocation_freezes_curriculum_and_sources_of_its_career_only(): void
    {
        $convocation = $this->openedConvocation();
        $career = Career::query()->firstOrFail();
        $source = AcademicSource::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 9,
            ])
            ->assertJsonValidationErrors('process');
        $this->assertSame(8, $career->fresh()->cantidad_ciclos_malla);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.sources.update', $source), [
                'nombre' => 'Fuente renombrada',
                'description' => null,
            ])
            ->assertJsonValidationErrors('process');
        $this->assertNotSame('Fuente renombrada', $source->fresh()->nombre);

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.curriculum.show', $career))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('curriculum.editable', false)
                ->where('curriculum.lock_reason', 'Convocatoria activa'));

        // Pausar la convocatoria de la carrera libera su malla y sus fuentes.
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), [
                'reason' => 'Corrección de las horas de dos materias antes de continuar.',
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertSame('pausada', $convocation->fresh()->estado);

        // Con la convocatoria pausada la malla se edita; como hay un expediente sin
        // enviar, el cambio pide confirmación y lo borra (I-32).
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 10,
                'confirm_purge' => 1,
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertSame(10, $career->fresh()->cantidad_ciclos_malla);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.sources.update', $source), [
                'nombre' => 'Fuente renombrada',
                'description' => null,
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertSuccessful();
        $this->assertSame('abierta', $convocation->fresh()->estado);
    }

    public function test_an_open_cycle_only_freezes_the_template_and_the_active_careers_structure(): void
    {
        $convocation = $this->openedConvocation();
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();

        // Una convocatoria abierta no admite alterar su programación ni añadir paralelos:
        // los docentes ya recibieron los alcances generados al abrirla.
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'scheduled_subject_id' => $scheduledSubject->id,
                'codes' => ['B'],
                'shift' => 'matutina',
            ])
            ->assertJsonValidationErrors('process');

        // Los registros nuevos siguen disponibles durante el proceso.
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
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), ['reason' => 'Corrección institucional del campus.'])
            ->assertSuccessful();
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['facultad', $faculty->id]), [
                'nombre' => $faculty->nombre,
                'campus' => CampusLocation::Chimbo->value,
                'confirm_purge' => 1,
            ])
            ->assertSuccessful();
        $this->assertSame(CampusLocation::Chimbo, $faculty->fresh()->campus);
        $this->assertSame(CampusLocation::Chimbo->value, $scheduledSubject->fresh()->getRawOriginal('campus_programacion'));

        // La convocatoria ya pausada permite completar su programación.
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
            ->where('plantilla_id', $syllabus->plantilla_id)
            ->where('editable_docente', true)
            ->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), [
                'reason' => 'Corrección de la malla antes de seguir elaborando.',
            ])
            ->assertSuccessful();
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $editableField]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => 'No debe guardarse durante una pausa.',
        ])->assertForbidden();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))->assertSuccessful();
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();

        // La pausa institucional detiene también a una convocatoria que sigue abierta.
        $process = $convocation->process()->firstOrFail();
        $this->transition($process, 'pausar', 'Ajuste institucional de la plantilla.')->assertSuccessful();
        $this->assertSame('abierta', $convocation->fresh()->estado);
        $this->actingAsTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $editableField]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => 'Tampoco debe guardarse durante la pausa institucional.',
        ])->assertForbidden();

        // Y mientras dure, la carrera no puede reanudar por su cuenta.
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), ['reason' => 'Pausa propia mientras tanto.'])
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
        $payload = ['source_ids' => [$source->id]];

        $this->actingAsCoordinator()->patch('/coordinacion/convocatorias/'.$convocation->id, $payload)->assertNotFound();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), ['reason' => 'Corrección de la malla antes de continuar.'])
            ->assertSuccessful();
        $this->actingAsCoordinator()->patch('/coordinacion/convocatorias/'.$convocation->id, $payload)->assertNotFound();
        $this->assertSame($convocation->career->nombre.' · '.$convocation->process->academicPeriod->codigo, $convocation->fresh()->nombre);

        // Cerrar no es de la carrera: lo decide Administración cerrando el proceso.
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'cerrar']))->assertNotFound();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->transition($convocation->process()->firstOrFail(), 'cerrar')->assertSuccessful();
        $this->assertSame('pausada', $convocation->fresh()->estado);
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertForbidden();
    }

    public function test_process_configuration_changes_only_in_preparation_or_pause(): void
    {
        $process = $this->openProcess();
        $payload = $this->processPayload($process->template);

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.processes.update', $process), $payload)
            ->assertForbidden();

        $this->transition($process, 'pausar', 'Se corrige la fecha de entrega.')->assertSuccessful();
        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.processes.update', $process), $payload)
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertSame($process->academicPeriod->codigo, $process->fresh()->nombre);
    }

    public function test_period_of_a_process_with_convocations_cannot_change(): void
    {
        $process = $this->createProcess($this->publishedTemplate(), 'Proceso con convocatoria');
        $source = $this->coordinatorSource();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $process->id,
            'source_ids' => [$source->id],
        ])->assertSuccessful();
        $this->transition($process, 'pausar', 'El período no puede reasignarse tras iniciar convocatorias.')->assertSuccessful();
        $otherPeriod = AcademicPeriod::query()->create([
            'codigo' => 'I-41-NO-CAMBIAR',
            'fecha_inicio' => '2027-06-01',
            'fecha_fin' => '2027-10-31',
        ]);

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.processes.update', $process), [
            ...$this->processPayload($process->template),
            'period_id' => $otherPeriod->id,
        ])->assertJsonValidationErrors('period_id');
    }

    /** @return array{period_id: string, starts_at: string, due_at: string} */
    private function processPayload(SyllabusTemplate $template): array
    {
        return [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => now()->subDay()->toIso8601String(),
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
            'source_ids' => [$source->id],
        ])->assertSuccessful();
        $convocation = Convocation::query()->where('proceso_id', $process->id)->firstOrFail();

        return $convocation->fresh();
    }

    private function transition(SyllabusProcess $process, string $transition, ?string $reason = null): TestResponse
    {
        return $this->actingAsAdministrator()->post(
            route('api.v1.admin.processes.transition', [$process, $transition]),
            $reason === null ? [] : ['reason' => $reason],
        );
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
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.sources.store'), [
            'nombre' => 'Fuente I-31',
            'description' => 'Documento de apoyo del periodo.',
        ])->assertSuccessful();
        $source = AcademicSource::query()->firstOrFail();
        $this->actingAsCoordinator()->putJson(route('api.v1.coordination.sources.content.update', $source), [
            'content' => "## Perfil\n\nEvidencia académica autorizada.",
        ])->assertSuccessful();

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
