<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Campus;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
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

    public function test_curricular_structure_belongs_directly_to_a_career(): void
    {
        $career = Career::query()->firstOrFail();
        $subject = Subject::query()->firstOrFail();

        $this->assertFalse(Schema::hasTable('mallas'));
        $this->assertTrue(Schema::hasColumn('carreras', 'codigo_malla'));
        $this->assertTrue(Schema::hasColumn('carreras', 'cantidad_ciclos_malla'));
        $this->assertTrue(Schema::hasColumn('asignaturas', 'carrera_id'));
        $this->assertTrue(Schema::hasColumn('silabos', 'carrera_id'));
        $this->assertFalse(Schema::hasColumn('asignaturas', 'malla_id'));
        $this->assertFalse(Schema::hasColumn('silabos', 'malla_id'));
        $this->assertFalse(Schema::hasColumn('paralelos', 'paralelo_activo'));
        $this->assertSame($career->id, $subject->carrera_id);
        $this->assertSame('MALLA-SW-2024', $career->codigo_malla);
        $this->assertSame(8, $career->cantidad_ciclos_malla);
    }

    public function test_career_requires_an_existing_faculty_and_curricular_structure_data(): void
    {
        $this->expectException(QueryException::class);

        Career::query()->create([
            'facultad_id' => (string) Str::uuid(),
            'codigo_carrera' => 'CARR-SIN-FACULTAD',
            'nombre' => 'Carrera sin facultad',
            'codigo_malla' => 'PLAN-SIN-FACULTAD',
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
                ->where('curriculum.code', $career->codigo_malla)
                ->where('curriculum.cycle_count', $career->cantidad_ciclos_malla)
                ->has('subjects', 1));
    }

    public function test_administrator_creates_a_career_with_its_fixed_curricular_structure(): void
    {
        $faculty = Faculty::query()->firstOrFail();
        $campus = Campus::query()->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'carrera'), [
                'faculty_id' => $faculty->id,
                'campus_id' => $campus->id,
                'modality' => StudyModality::Hibrida->value,
                'code' => 'CARR-DEMO',
                'nombre' => 'Carrera de demostración',
                'curriculum_code' => 'PLAN-DEMO-2026',
                'cycle_count' => 9,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('carreras', [
            'codigo_carrera' => 'CARR-DEMO',
            'codigo_malla' => 'PLAN-DEMO-2026',
            'cantidad_ciclos_malla' => 9,
        ]);
    }

    public function test_administrator_cannot_create_a_career_without_curricular_code_or_cycle_count(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.store', 'carrera'), [
                'faculty_id' => Faculty::query()->firstOrFail()->id,
                'campus_id' => Campus::query()->firstOrFail()->id,
                'modality' => StudyModality::Presencial->value,
                'code' => 'CARR-INCOMPLETA',
                'nombre' => 'Carrera incompleta',
            ])
            ->assertJsonValidationErrors(['curriculum_code', 'cycle_count']);
    }

    public function test_coordinator_configures_own_career_structure_and_creates_subjects_directly_in_it(): void
    {
        $career = Career::query()->findOrFail($this->coordinatorContext->carrera_id);

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.curriculum.configuration.update', $career->id), [
                'code' => 'PLAN-SW-2027',
                'cycle_count' => 10,
            ])
            ->assertSuccessful();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.store', 'asignatura'), [
                'code' => 'SW-701',
                'nombre' => 'Arquitectura Empresarial',
                'cycle' => 7,
                'organization_unit' => 'Unidad profesional',
                'horas_ac' => 48,
                'horas_pae' => 32,
                'horas_aa' => 64,
                'creditos' => 4,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('carreras', [
            'id' => $career->id,
            'codigo_malla' => 'PLAN-SW-2027',
            'cantidad_ciclos_malla' => 10,
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
                'cycle' => 2,
                'position' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Posición de la materia actualizada.');

        $this->assertDatabaseHas('asignaturas', [
            'id' => $subject->id,
            'ciclo_asignatura' => 2,
            'orden_asignatura_en_ciclo' => 4,
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

        $this->assertDatabaseMissing('asignaturas', ['id' => $subject->id]);
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
            ->patchJson(route('api.v1.coordination.academic.status', [
                'entity' => 'asignatura',
                'record' => $otherSubject->id,
            ]), ['active' => false])
            ->assertNotFound();
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

    private function createCareer(string $code): Career
    {
        $faculty = Faculty::query()->create([
            'codigo_facultad' => "FAC-{$code}",
            'nombre' => "Facultad {$code}",
            'activo' => true,
        ]);

        return Career::query()->create([
            'facultad_id' => $faculty->id,
            'campus_id' => Campus::query()->firstOrFail()->id,
            'modalidad' => StudyModality::Presencial,
            'codigo_carrera' => $code,
            'nombre' => "Carrera {$code}",
            'codigo_malla' => "PLAN-{$code}",
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
