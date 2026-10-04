<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
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

    public function test_administrator_creates_a_period_without_manual_code_or_weeks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-16 12:00:00', 'America/Guayaquil'));

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'periodo'), [
                'starts_on' => '2026-08-17',
                'ends_on' => '2026-12-18',
                'teaching_weeks' => 1,
            ])
            ->assertSuccessful();

        $period = AcademicPeriod::query()->whereDate('fecha_inicio_periodo', '2026-08-17')->firstOrFail();

        $this->assertSame('Agosto-Diciembre 2026', $period->codigo);
        $this->assertSame(18, $period->semanas_lectivas);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.academic.update', ['periodo', $period->id]), [
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-12-04',
                'teaching_weeks' => 1,
            ])
            ->assertSuccessful();

        $this->assertSame('Septiembre-Diciembre 2026', $period->fresh()->codigo);
        $this->assertSame(14, $period->fresh()->semanas_lectivas);
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
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.coordination.academic.scheduled-subjects.index'))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->where('scheduledSubjects.0.subject_code', $scheduledSubject->subject->codigo_asignatura));
    }

    public function test_preparing_a_period_again_does_not_duplicate_existing_records(): void
    {
        $source = Subject::query()->firstOrFail();
        $subject = $source->replicate();
        $subject->codigo_asignatura = 'SW-602';
        $subject->nombre = 'Seguridad de Software';
        $subject->save();
        $period = AcademicPeriod::query()->firstOrFail();

        $payload = [
            'period_id' => $period->id,
            'subjects' => [[
                'id' => $subject->id,
                'parallels' => [['code' => 'A', 'shift' => 'matutina']],
            ]],
        ];

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), $payload)
            ->assertSuccessful();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), $payload)
            ->assertSuccessful();

        $scheduled = ScheduledSubject::query()->where('asignatura_id', $subject->id)->get();
        $this->assertCount(1, $scheduled);
        $this->assertSame(1, Parallel::query()
            ->where('programacion_asignatura_id', $scheduled->firstOrFail()->id)
            ->count());
    }

    public function test_preparing_a_period_assigns_a_verified_career_teacher_in_the_same_transaction(): void
    {
        $subject = Subject::query()->firstOrFail()->replicate();
        $subject->codigo_asignatura = 'SW-605';
        $subject->nombre = 'Ingeniería de Requisitos';
        $subject->save();
        $period = AcademicPeriod::query()->firstOrFail();
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), [
                'period_id' => $period->id,
                'subjects' => [[
                    'id' => $subject->id,
                    'parallels' => [['code' => 'A', 'shift' => 'matutina', 'teacher_user_id' => $teacher->id]],
                ]],
            ])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Período preparado: 1 materia programada, 1 paralelo y 1 docente asignado para 1 materia.');

        $scheduled = ScheduledSubject::query()->where('asignatura_id', $subject->id)->firstOrFail();
        $parallel = Parallel::query()->where('programacion_asignatura_id', $scheduled->id)->firstOrFail();
        $assignment = TeacherAssignment::query()->where('paralelo_id', $parallel->id)->firstOrFail();
        $this->assertSame($teacher->id, $assignment->roleAssignment->usuario_id);
    }

    public function test_preparing_a_period_rejects_an_invalid_teacher_without_creating_any_subject(): void
    {
        $subject = Subject::query()->firstOrFail()->replicate();
        $subject->codigo_asignatura = 'SW-606';
        $subject->nombre = 'Arquitectura';
        $subject->save();
        $period = AcademicPeriod::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), [
                'period_id' => $period->id,
                'subjects' => [[
                    'id' => $subject->id,
                    'parallels' => [['code' => 'A', 'shift' => 'matutina', 'teacher_user_id' => $this->administrator->id]],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects');

        $this->assertDatabaseMissing('asignaturas_ofertadas', ['asignatura_id' => $subject->id]);
    }

    public function test_preparing_a_period_requires_every_pending_active_subject(): void
    {
        $first = Subject::query()->firstOrFail()->replicate();
        $first->codigo_asignatura = 'SW-607';
        $first->nombre = 'Seguridad';
        $first->save();
        $second = Subject::query()->firstOrFail()->replicate();
        $second->codigo_asignatura = 'SW-608';
        $second->nombre = 'Redes';
        $second->save();
        $period = AcademicPeriod::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), [
                'period_id' => $period->id,
                'subjects' => [[
                    'id' => $first->id,
                    'parallels' => [['code' => 'A', 'shift' => 'matutina']],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects');

        $this->assertDatabaseMissing('asignaturas_ofertadas', ['asignatura_id' => $first->id]);
        $this->assertDatabaseMissing('asignaturas_ofertadas', ['asignatura_id' => $second->id]);
    }

    public function test_preparing_a_period_rejects_duplicate_parallel_codes_after_normalization(): void
    {
        $source = Subject::query()->firstOrFail();
        $subject = $source->replicate();
        $subject->codigo_asignatura = 'SW-603';
        $subject->nombre = 'Pruebas de Software';
        $subject->save();
        $period = AcademicPeriod::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), [
                'period_id' => $period->id,
                'subjects' => [[
                    'id' => $subject->id,
                    'parallels' => [
                        ['code' => 'A', 'shift' => 'matutina'],
                        ['code' => ' A ', 'shift' => 'matutina'],
                    ],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subjects.0.parallels' => 'Hay paralelos repetidos en la misma materia.']);

        $this->assertDatabaseMissing('asignaturas_ofertadas', ['asignatura_id' => $subject->id]);
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

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), [
                'period_id' => AcademicPeriod::query()->firstOrFail()->id,
                'subjects' => [
                    ['id' => $first->id, 'parallels' => [['code' => 'A', 'shift' => 'matutina']]],
                    ['id' => $second->id, 'parallels' => [['code' => 'A', 'shift' => 'matutina']]],
                ],
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('asignaturas_ofertadas', ['asignatura_id' => $first->id]);
        $this->assertDatabaseHas('asignaturas_ofertadas', ['asignatura_id' => $second->id]);
    }

    public function test_administration_deletes_a_period_without_convocation_together_with_its_planning(): void
    {
        $period = AcademicPeriod::query()->firstOrFail();
        $scheduled = ScheduledSubject::query()->where('periodo_academico_id', $period->id)->firstOrFail();
        $parallel = Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'Z', 'jornada' => 'matutina']);

        // I-172: sin convocatoria no hay sílabos; la programación del periodo se va con él.
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.academic.destroy', ['periodo', $period->id]))
            ->assertOk();

        $this->assertDatabaseMissing('periodos_academicos', ['periodo_academico_id' => $period->id]);
        $this->assertDatabaseMissing('asignaturas_ofertadas', ['asignatura_ofertada_id' => $scheduled->id]);
        $this->assertDatabaseMissing('paralelos', ['paralelo_id' => $parallel->id]);
    }

    public function test_preparing_a_period_does_not_reuse_an_inactive_scheduled_subject(): void
    {
        $source = Subject::query()->firstOrFail();
        $subject = $source->replicate();
        $subject->codigo_asignatura = 'SW-604';
        $subject->nombre = 'Materia inactiva';
        $subject->save();
        $period = AcademicPeriod::query()->firstOrFail();
        $career = Career::query()->findOrFail($subject->carrera_id);
        $scheduled = ScheduledSubject::query()->create([
            'periodo_academico_id' => $period->id,
            'asignatura_id' => $subject->id,
            'activo' => false,
        ]);

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), [
                'period_id' => $period->id,
                'subjects' => [[
                    'id' => $subject->id,
                    'parallels' => [['code' => 'A', 'shift' => 'matutina']],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects');

        $this->assertFalse($scheduled->fresh()->activo);
        $this->assertDatabaseMissing('paralelos', ['asignatura_ofertada_id' => $scheduled->id]);
    }

    public function test_every_parallel_requires_a_shift_when_preparing_or_creating(): void
    {
        $subject = Subject::query()->firstOrFail()->replicate();
        $subject->codigo_asignatura = 'SW-610';
        $subject->nombre = 'Materia sin jornada';
        $subject->save();
        $period = AcademicPeriod::query()->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.period.prepare'), [
                'period_id' => $period->id,
                'subjects' => [['id' => $subject->id, 'parallels' => [['code' => 'A', 'shift' => null]]]],
            ])
            ->assertJsonValidationErrors('subjects.0.parallels.0.shift');

        $scheduled = ScheduledSubject::query()->firstOrFail();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.parallels.store'), [
                'scheduled_subject_id' => $scheduled->id,
                'codes' => ['B'],
            ])
            ->assertJsonValidationErrors('shift');

        $this->expectException(QueryException::class);
        Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'Q']);
    }

    public function test_coordinator_edits_code_and_shift_of_a_parallel_with_audit(): void
    {
        $scheduled = ScheduledSubject::query()->firstOrFail();
        $parallel = Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'B', 'jornada' => 'matutina']);
        Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'C', 'jornada' => 'matutina']);

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
        $scheduled = ScheduledSubject::query()->firstOrFail();
        $withTeacher = Parallel::query()->where('programacion_asignatura_id', $scheduled->id)->whereHas('teacherAssignments')->firstOrFail();
        $free = Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'B', 'jornada' => 'vespertina']);

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
        $scheduled = ScheduledSubject::query()->firstOrFail()->replicate();
        $scheduled->asignatura_id = $subject->id;
        $scheduled->save();
        $foreign = Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'A', 'jornada' => 'matutina']);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $foreign), ['code' => 'B', 'shift' => 'nocturna'])
            ->assertNotFound();
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $foreign))
            ->assertNotFound();
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
