<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Tests\TestCase;

class AcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
    }

    public function test_academic_forms_receive_authoritative_modality_and_shift_options(): void
    {
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.academic.index'))
            ->assertOk()
            ->assertJsonPath('options.modalityOptions', StudyModality::options())
            ->assertJsonPath('options.shiftOptions', Parallel::shiftOptions());

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.scheduled-subjects.index'))
            ->assertOk()
            ->assertJsonPath('options.modalityOptions', StudyModality::options())
            ->assertJsonPath('options.shiftOptions', Parallel::shiftOptions());
    }

    public function test_curricular_structure_belongs_directly_to_a_career(): void
    {
        $career = Career::query()->firstOrFail();
        $subject = Subject::query()->firstOrFail();

        $this->assertFalse(Schema::hasTable('mallas'));
        $this->assertFalse(Schema::hasTable('campus'));
        $this->assertTrue(Schema::hasColumn('facultades', 'campus_facultad'));
        $this->assertFalse(Schema::hasColumn('carreras', 'campus_id'));
        $this->assertFalse(Schema::hasColumn('asignaturas_ofertadas', 'campus_programacion'));
        $this->assertFalse(Schema::hasColumn('asignaturas_ofertadas', 'campus_id'));
        $this->assertFalse(Schema::hasColumn('facultades', 'codigo_facultad'));
        $this->assertFalse(Schema::hasColumn('carreras', 'codigo_malla'));
        $this->assertTrue(Schema::hasColumn('carreras', 'codigo_carrera'));
        $this->assertTrue(Schema::hasColumn('carreras', 'numero_ciclos'));
        $this->assertTrue(Schema::hasColumn('asignaturas', 'carrera_id'));
        $this->assertFalse(Schema::hasColumn('silabos', 'carrera_id'));
        $this->assertFalse(Schema::hasColumn('asignaturas', 'malla_id'));
        $this->assertFalse(Schema::hasColumn('silabos', 'malla_id'));
        $this->assertFalse(Schema::hasColumn('paralelos', 'paralelo_activo'));
        $this->assertSame($career->id, $subject->carrera_id);
        $this->assertSame('SOFTWARE', $career->codigo_carrera);
        $this->assertSame(8, $career->cantidad_ciclos_malla);
    }

    public function test_career_requires_an_existing_faculty_and_curricular_structure_data(): void
    {
        $this->expectException(QueryException::class);

        Career::query()->create([
            'facultad_id' => (string) Str::uuid(),
            'codigo_carrera' => 'CARR-SIN-FACULTAD',
            'nombre' => 'Carrera sin facultad',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
    }

    public function test_coordinator_opens_their_career_curricular_structure(): void
    {
        $career = Career::query()->findOrFail($this->coordinatorContext->carrera_id);

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.curriculum.show', $career->id))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('career.id', $career->id)
                ->where('curriculum.id', $career->id)
                ->where('curriculum.cycle_count', $career->cantidad_ciclos_malla)
                ->missing('curriculum.code')
                ->has('subjects', 1));
    }

    public function test_administrator_creates_a_career_with_required_code_and_cycle_count(): void
    {
        $faculty = Faculty::query()->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'carrera'), [
                'faculty_id' => $faculty->id,
                'modality' => StudyModality::Hibrida->value,
                'code' => 'CARR-DEMO',
                'nombre' => 'Carrera de demostración',
                'cycle_count' => 9,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('carreras', [
            'codigo_carrera' => 'CARR-DEMO',
            'numero_ciclos' => 9,
        ]);
    }

    public function test_administrator_cannot_create_a_career_without_career_code_or_cycle_count(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'carrera'), [
                'faculty_id' => Faculty::query()->firstOrFail()->id,
                'modality' => StudyModality::Presencial->value,
                'nombre' => 'Carrera incompleta',
            ])
            ->assertJsonValidationErrors(['code', 'cycle_count']);
    }

    public function test_administrator_creates_a_period_whose_weeks_come_from_the_dates_unless_corrected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-16 12:00:00', 'America/Guayaquil'));

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'periodo'), [
                'starts_on' => '2026-08-17',
                'ends_on' => '2026-12-18',
            ])
            ->assertSuccessful();

        $period = AcademicPeriod::query()->whereDate('fecha_inicio_periodo', '2026-08-17')->firstOrFail();

        $this->assertSame('Agosto-Diciembre 2026', $period->codigo);
        $this->assertSame(18, $period->semanas_lectivas);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['periodo', $period->id]), [
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-12-04',
                'teaching_weeks' => 12,
            ])
            ->assertSuccessful();

        $this->assertSame('Septiembre-Diciembre 2026', $period->fresh()->codigo);
        $this->assertSame(12, $period->fresh()->semanas_lectivas);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['periodo', $period->id]), [
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-12-04',
                'teaching_weeks' => 15,
            ])
            ->assertJsonValidationErrors(['teaching_weeks' => 'Las semanas de clase no pueden superar las 14 semanas entre las fechas.']);
    }

    public function test_period_start_date_must_be_today_or_later(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC'));

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'periodo'), [
                'starts_on' => '2026-09-22',
                'ends_on' => '2026-12-18',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'starts_on' => 'La fecha de inicio debe ser hoy o una fecha posterior.',
            ]);

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'periodo'), [
                'starts_on' => '2026-09-23',
                'ends_on' => '2026-12-18',
            ])
            ->assertCreated()
            ->assertJsonStructure(['message', 'id']);
    }

    public function test_period_rejects_a_range_longer_than_52_calendar_weeks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-12-31 12:00:00', 'America/Guayaquil'));

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'periodo'), [
                'starts_on' => '2026-01-01',
                'ends_on' => '2027-01-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on']);
    }

    public function test_coordinator_configures_own_career_structure_and_creates_subjects_directly_in_it(): void
    {
        $career = Career::query()->findOrFail($this->coordinatorContext->carrera_id);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'cycle_count' => 10,
            ])
            ->assertSuccessful();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.store', 'asignatura'), [
                'code' => 'SW-701',
                'nombre' => 'Arquitectura Empresarial',
                'cycle' => 7,
                'organization_unit' => 'profesional',
                'horas_ac' => 48,
                'horas_pae' => 32,
                'horas_aa' => 64,
                'creditos' => 4,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('carreras', [
            'carrera_id' => $career->id,
            'numero_ciclos' => 10,
        ]);
        $this->assertDatabaseHas('asignaturas', [
            'carrera_id' => $career->id,
            'codigo_asignatura' => 'SW-701',
            'ciclo_asignatura' => 7,
            'total_horas_asignatura' => 144,
        ]);
    }

    public function test_spa_reads_and_repositions_a_subject_through_the_api_contract(): void
    {
        $career = Career::query()->findOrFail($this->coordinatorContext->carrera_id);
        $subject = Subject::query()->where('carrera_id', $career->id)->firstOrFail();
        $second = Subject::query()->create([
            'carrera_id' => $career->id,
            'codigo_asignatura' => 'SW-TEST-2',
            'nombre' => 'Segunda materia',
            'ciclo' => $subject->ciclo,
            'orden_en_ciclo' => 1,
            'activo' => true,
        ]);
        $third = Subject::query()->create([
            'carrera_id' => $career->id,
            'codigo_asignatura' => 'SW-TEST-3',
            'nombre' => 'Tercera materia',
            'ciclo' => $subject->ciclo,
            'orden_en_ciclo' => 2,
            'activo' => true,
        ]);

        $this->actingAsCoordinator()
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson("/api/v1/coordination/academic/curriculum/{$career->id}")
            ->assertOk()
            ->assertJsonPath('career.id', $career->id)
            ->assertJsonPath('curriculum.id', $career->id)
            ->assertJsonFragment([
                'id' => $subject->id,
                'code' => $subject->codigo_asignatura,
            ]);

        $this->actingAsCoordinator()
            ->withHeader('Origin', 'http://localhost:5173')
            ->patchJson("/api/v1/coordination/academic/curriculum/{$career->id}/subject-layout", [
                'subject_id' => $subject->id,
                'cycle' => $subject->ciclo,
                'position' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Posición de la materia actualizada.');

        $this->assertDatabaseHas('asignaturas', [
            'asignatura_id' => $subject->id,
            'ciclo_asignatura' => $subject->ciclo,
            'orden_asignatura_en_ciclo' => 2,
        ]);
        $this->assertSame([$second->id, $third->id, $subject->id],
            Subject::query()->where('carrera_id', $career->id)->where('ciclo', $subject->ciclo)
                ->orderBy('orden_en_ciclo')->pluck('id')->all());

        $this->actingAsCoordinator()
            ->withHeader('Origin', 'http://localhost:5173')
            ->patchJson("/api/v1/coordination/academic/curriculum/{$career->id}/subject-layout", [
                'subject_id' => $subject->id,
                'cycle' => $subject->ciclo + 1,
                'position' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cycle');
        $this->assertDatabaseHas('asignaturas', [
            'asignatura_id' => $subject->id,
            'ciclo_asignatura' => $subject->ciclo,
            'orden_asignatura_en_ciclo' => 2,
        ]);
    }

    public function test_spa_deletes_an_unused_subject_with_the_curriculum_operation(): void
    {
        $career = Career::query()->findOrFail($this->coordinatorContext->carrera_id);
        $subject = Subject::query()->create([
            'carrera_id' => $career->id,
            'codigo_asignatura' => 'SW-TEMP',
            'nombre' => 'Materia temporal',
            'ciclo' => 1,
            'activo' => true,
        ]);

        $this->actingAsCoordinator()
            ->withHeader('Origin', 'http://localhost:5173')
            ->deleteJson("/api/v1/coordination/academic/curriculum/{$career->id}/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Materia eliminada de la malla.');

        $this->assertDatabaseMissing('asignaturas', ['asignatura_id' => $subject->id]);
    }

    public function test_coordinator_cannot_access_another_careers_curricular_structure_or_subjects(): void
    {
        $otherCareer = $this->createCareer('OTRA');

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.curriculum.show', $otherCareer->id))
            ->assertNotFound();

        $otherSubject = Subject::query()->create([
            'carrera_id' => $otherCareer->id,
            'codigo_asignatura' => 'OTR-101',
            'nombre' => 'Materia ajena',
            'ciclo' => 1,
            'activo' => true,
        ]);
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.update', ['asignatura', $otherSubject->id]), [
                'code' => 'OTR-101',
                'nombre' => 'Materia renombrada',
            ])
            ->assertForbidden();
        $this->assertSame('Materia ajena', $otherSubject->fresh()->nombre);
    }

    public function test_scheduled_subjects_keep_their_career_scope_after_curricula_removal(): void
    {
        $scheduledSubject = Parallel::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.scheduled-subjects.index'))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('scheduledSubjects.0.subject_code', $scheduledSubject->subject->codigo_asignatura));
    }

    public function test_the_same_parallel_code_is_allowed_in_different_subjects(): void
    {
        $first = Subject::query()->firstOrFail()->replicate();
        $first->codigo_asignatura = 'SW-P7-037';
        $first->nombre = 'Inteligencia Artificial';
        $first->save();
        $second = Subject::query()->firstOrFail()->replicate();
        $second->codigo_asignatura = 'SW-IC8-038';
        $second->nombre = 'Gestión de las Tecnologías de la Información';
        $second->save();

        foreach ([$first, $second] as $subject) {
            $this->actingAsCoordinator()
                ->postJson(route('api.v1.coordination.academic.parallels.store'), ['subject_id' => $subject->id, 'codes' => ['A'], 'shift' => 'matutina', 'user_id' => $this->coordinator->id])
                ->assertSuccessful();
        }

        $this->assertDatabaseHas('paralelos', ['asignatura_id' => $first->id]);
        $this->assertDatabaseHas('paralelos', ['asignatura_id' => $second->id]);
    }

    public function test_a_subject_rejects_duplicate_parallel_codes_ignoring_case(): void
    {
        $scheduled = Parallel::query()->firstOrFail();
        $code = $scheduled->codigo;

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), ['subject_id' => $scheduled->asignatura_id, 'codes' => [strtolower($code)], 'shift' => 'matutina', 'user_id' => $this->coordinator->id])
            ->assertJsonValidationErrors('codes');
        $this->assertSame(1, Parallel::query()->where('asignatura_id', $scheduled->asignatura_id)->count());
    }

    public function test_the_scheduled_subjects_list_has_no_period_and_includes_subjects_without_parallels(): void
    {
        $subject = Subject::query()->firstOrFail()->replicate();
        $subject->codigo_asignatura = 'SW-620';
        $subject->nombre = 'Materia nueva de la malla';
        $subject->save();

        $response = $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.scheduled-subjects.index'))
            ->assertOk()
            ->assertJsonMissingPath('selectedPeriodId');
        $row = collect($response->json('scheduledSubjects'))->firstWhere('subject_id', $subject->id);

        $this->assertNotNull($row);
        $this->assertSame([], $row['parallels']);
        $this->assertArrayNotHasKey('period_id', $row);
    }

    public function test_a_career_with_only_retired_roles_without_history_can_be_deleted(): void
    {
        $career = $this->createCareer('RETIRADA');
        $user = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $role = RoleAssignment::query()->create(['usuario_id' => $user->id, 'rol' => 'docente', 'carrera_id' => $career->id, 'activo' => true]);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.academic.destroy', ['carrera', $career->id]))
            ->assertUnprocessable();

        $role->update(['activo' => false]);
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.academic.destroy', ['carrera', $career->id]))
            ->assertOk();

        $this->assertDatabaseMissing('carreras', ['carrera_id' => $career->id]);
        $this->assertDatabaseMissing('asignaciones_rol', ['asignacion_rol_id' => $role->id]);
    }

    public function test_deleting_a_period_without_convocation_keeps_the_fixed_parallels(): void
    {
        $period = AcademicPeriod::query()->firstOrFail();
        $scheduled = Parallel::query()->firstOrFail();
        $parallel = Parallel::query()->create(['asignatura_id' => $scheduled->asignatura_id, 'codigo' => 'Z', 'jornada' => 'matutina']);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.academic.destroy', ['periodo', $period->id]))
            ->assertOk();

        $this->assertDatabaseMissing('periodos_academicos', ['periodo_academico_id' => $period->id]);
        $this->assertDatabaseHas('paralelos', ['paralelo_id' => $parallel->id]);
    }

    public function test_a_period_with_a_convocation_cannot_be_deleted_and_says_why(): void
    {
        $period = AcademicPeriod::query()->firstOrFail();
        SyllabusProcess::query()->create([
            'periodo_academico_id' => $period->id,
            'inicia_en' => now()->subDay(),
            'entrega_en' => now()->addMonth(),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.academic.destroy', ['periodo', $period->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'record' => 'Este período ya tiene una convocatoria con su historial, por eso no se puede eliminar.',
            ]);

        $this->assertDatabaseHas('periodos_academicos', ['periodo_academico_id' => $period->id]);
    }

    public function test_a_period_with_a_convocation_is_locked_with_its_reason(): void
    {
        $period = AcademicPeriod::query()->firstOrFail();
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => $period->id,
            'inicia_en' => now()->subDay(),
            'entrega_en' => now()->addMonth(),
            'estado' => SyllabusProcess::STATE_CLOSED,
        ]);

        $payload = $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.academic.index'))
            ->assertOk()
            ->json('catalogs.periods');

        $this->assertSame('Convocatoria cerrada', collect($payload)->firstWhere('id', $period->id)['lock_reason']);

        $process->update(['estado' => SyllabusProcess::STATE_OPEN]);

        $payload = $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.academic.index'))
            ->assertOk()
            ->json('catalogs.periods');

        $this->assertSame('Convocatoria activa', collect($payload)->firstWhere('id', $period->id)['lock_reason']);
    }

    public function test_every_parallel_requires_a_shift_when_creating(): void
    {
        $scheduled = Parallel::query()->firstOrFail();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'scheduled_subject_id' => $scheduled->id,
                'codes' => ['B'],
            ])
            ->assertJsonValidationErrors('shift');

        $this->expectException(QueryException::class);
        Parallel::query()->create(['asignatura_id' => $scheduled->asignatura_id, 'codigo' => 'Q']);
    }

    public function test_creating_a_parallel_requires_a_teacher_and_writes_nothing_without_one(): void
    {
        $subject = Subject::query()->firstOrFail();
        $parallels = Parallel::query()->count();
        $audits = AuditEvent::query()->count();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'subject_id' => $subject->id, 'codes' => ['B'], 'shift' => 'matutina',
            ])
            ->assertJsonValidationErrors(['user_id' => 'Seleccione el docente del paralelo.']);

        $this->assertSame($parallels, Parallel::query()->count());
        $this->assertSame($audits, AuditEvent::query()->count());
    }

    public function test_creating_parallels_assigns_the_selected_teacher_and_audits_both_records(): void
    {
        $subject = Subject::query()->firstOrFail();
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'subject_id' => $subject->id, 'codes' => ['B', 'C'], 'shift' => 'vespertina', 'user_id' => $teacher->id,
            ])
            ->assertSuccessful();

        foreach (['B', 'C'] as $code) {
            $parallel = Parallel::query()->where('asignatura_id', $subject->id)->where('codigo', $code)->firstOrFail();
            $assignment = TeacherAssignment::query()->where('paralelo_id', $parallel->id)->where('activo', true)->sole();
            $this->assertSame($teacher->id, $assignment->roleAssignment->usuario_id);
            $this->assertSame('vespertina', $parallel->jornada);
            $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.paralelo.creacion', 'recurso_id' => $parallel->id]);
            $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.asignacion_docente.creacion', 'recurso_id' => $assignment->id]);
        }
    }

    public function test_a_teacher_from_another_career_rolls_back_parallel_creation_and_its_audit(): void
    {
        $subject = Subject::query()->firstOrFail();
        $career = $this->createCareer('OTRA-DOCENTE');
        $teacher = User::factory()->create();
        RoleAssignment::query()->create(['usuario_id' => $teacher->id, 'carrera_id' => $career->id, 'rol' => 'docente', 'activo' => true]);
        $parallels = Parallel::query()->count();
        $assignments = TeacherAssignment::query()->count();
        $audits = AuditEvent::query()->count();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'subject_id' => $subject->id, 'codes' => ['B', 'C'], 'shift' => 'matutina', 'user_id' => $teacher->id,
            ])
            ->assertJsonValidationErrors('user_id');

        $this->assertSame($parallels, Parallel::query()->count());
        $this->assertSame($assignments, TeacherAssignment::query()->count());
        $this->assertSame($audits, AuditEvent::query()->count());
    }

    public function test_an_inactive_or_unverified_teacher_cannot_be_used_to_create_a_parallel(): void
    {
        $subject = Subject::query()->firstOrFail();
        foreach ([User::factory()->inactive()->create(), User::factory()->unverified()->create()] as $teacher) {
            RoleAssignment::query()->create(['usuario_id' => $teacher->id, 'carrera_id' => $subject->carrera_id, 'rol' => 'docente', 'activo' => true]);
            $this->actingAsCoordinator()
                ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                    'subject_id' => $subject->id, 'codes' => ['B'], 'shift' => 'matutina', 'user_id' => $teacher->id,
                ])
                ->assertJsonValidationErrors('user_id');
        }
        $this->assertDatabaseMissing('paralelos', ['asignatura_id' => $subject->id, 'codigo_paralelo' => 'B']);
    }

    public function test_coordinator_edits_code_and_shift_of_a_parallel_with_audit(): void
    {
        $scheduled = Parallel::query()->firstOrFail();
        $parallel = Parallel::query()->create(['asignatura_id' => $scheduled->asignatura_id, 'codigo' => 'B', 'jornada' => 'matutina']);
        Parallel::query()->create(['asignatura_id' => $scheduled->asignatura_id, 'codigo' => 'C', 'jornada' => 'matutina']);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $parallel), ['code' => 'c', 'shift' => 'nocturna'])
            ->assertJsonValidationErrors(['code' => 'Ya existe el paralelo c en esta materia.']);
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $parallel), ['code' => 'D', 'shift' => null])
            ->assertJsonValidationErrors('shift');
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $parallel), ['code' => ' D ', 'shift' => 'nocturna'])
            ->assertSuccessful();

        $parallel->refresh();
        $this->assertSame('D', $parallel->codigo);
        $this->assertSame('nocturna', $parallel->jornada);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.paralelo.actualizacion', 'recurso_id' => $parallel->id]);

    }

    public function test_coordinator_deletes_only_a_parallel_without_teacher_or_syllabus(): void
    {
        $scheduled = Parallel::query()->firstOrFail();
        $withTeacher = Parallel::query()->where('asignatura_id', $scheduled->asignatura_id)->whereHas('teacherAssignments')->firstOrFail();
        $free = Parallel::query()->create(['asignatura_id' => $scheduled->asignatura_id, 'codigo' => 'B', 'jornada' => 'vespertina']);

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $withTeacher))
            ->assertJsonValidationErrors('record');
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $free))
            ->assertSuccessful();

        $this->assertDatabaseMissing('paralelos', ['paralelo_id' => $free->id]);
        $this->assertDatabaseHas('paralelos', ['paralelo_id' => $withTeacher->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.paralelo.eliminacion', 'recurso_id' => $free->id]);
    }

    public function test_coordinator_cannot_change_a_parallel_of_another_career(): void
    {
        $career = $this->createCareer('OTRA-1');
        $subject = Subject::query()->firstOrFail()->replicate();
        $subject->carrera_id = $career->id;
        $subject->codigo_asignatura = 'OT-1';
        $subject->save();
        $scheduled = Parallel::query()->firstOrFail()->replicate();
        $scheduled->asignatura_id = $subject->id;
        $scheduled->save();
        $foreign = $scheduled;

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $foreign), ['code' => 'B', 'shift' => 'nocturna'])
            ->assertNotFound();
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $foreign))
            ->assertNotFound();
    }

    public function test_administrator_deactivates_and_reactivates_a_career_with_audit(): void
    {
        $career = $this->createCareer('PAUSA');
        $user = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $role = RoleAssignment::query()->create(['usuario_id' => $user->id, 'rol' => 'docente', 'carrera_id' => $career->id, 'activo' => true]);
        $status = fn (bool $active) => $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.status', ['carrera', $career->id]), ['active' => $active]);

        $status(false)->assertUnprocessable()->assertJsonPath('errors.record.0', 'Retire primero a los usuarios de la carrera.');
        $role->update(['activo' => false]);

        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->valueOrFail('id'),
            'inicia_en' => now()->subDay(), 'entrega_en' => now()->addMonth(), 'estado' => SyllabusProcess::STATE_PAUSED,
        ]);
        Convocation::query()->create(['carrera_id' => $career->id, 'proceso_id' => $process->id, 'estado' => Convocation::STATE_PREPARATION]);
        $status(false)->assertUnprocessable();
        $process->update(['estado' => SyllabusProcess::STATE_CLOSED]);

        $status(false)->assertOk();
        $this->assertFalse($career->fresh()->activo);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.carrera.desactivacion', 'recurso_id' => $career->id]);
        $careers = $this->actingAsAdministrator()->getJson(route('api.v1.admin.academic.index'))->assertOk()->json('catalogs.careers');
        $this->assertContains(['id' => $career->id, 'active' => false], array_map(fn (array $row) => ['id' => $row['id'], 'active' => $row['active']], $careers));

        $status(true)->assertOk();
        $this->assertTrue($career->fresh()->activo);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.carrera.reactivacion', 'recurso_id' => $career->id]);
    }

    public function test_coordinator_cannot_change_a_career_status(): void
    {
        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.admin.academic.status', ['carrera', $this->coordinatorContext->carrera_id]), ['active' => false])
            ->assertForbidden();
    }

    private function createCareer(string $code): Career
    {
        $faculty = Faculty::query()->create([
            'nombre' => "Facultad {$code}",
            'campus' => CampusLocation::Matriz,
            'activo' => true,
        ]);

        return Career::query()->create([
            'facultad_id' => $faculty->id,
            'modalidad' => StudyModality::Presencial,
            'codigo_carrera' => $code,
            'nombre' => "Carrera {$code}",
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
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
}
