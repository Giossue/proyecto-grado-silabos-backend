<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class ApiSyllabiIndexTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173']]);
    }

    public function test_teacher_can_list_only_its_syllabi_through_api(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.teacher.syllabi.index', ['q' => 'inexistente']))
            ->assertOk()
            ->assertJsonPath('filters.q', 'inexistente')
            ->assertJsonPath('filters.state', null)
            ->assertJsonStructure([
                'syllabi' => ['data', 'current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_coordinator_cannot_list_teacher_syllabi(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $assignment = $coordinator->roleAssignments()->firstOrFail();

        $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $assignment->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertForbidden();
    }

    public function test_teacher_sees_only_current_period_syllabi_and_the_rest_go_to_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $session = ['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id];
        $request = fn (string $route, array $parameters = []) => $this->actingAs($teacher)
            ->withSession($session)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route($route, $parameters));

        $template = SyllabusTemplate::query()->create(['nombre' => 'Plantilla historial', 'activo' => true]);
        $convocation = Convocation::query()->create([
            'carrera_id' => Career::query()->valueOrFail('id'),
            'proceso_id' => $this->openSyllabusProcess($template->id)->id,
            'estado' => 'abierta',
        ]);
        Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => TeacherAssignment::query()->forUser($teacher->id)->firstOrFail()->paralelo_id,
            'asignatura_id' => Subject::query()->valueOrFail('id'),
            'estado' => 'borrador',
            'version_bloqueo' => 1,
        ]);

        AcademicPeriod::query()->update(['fecha_fin' => now()->addMonth()->toDateString()]);
        $current = $request('api.v1.teacher.syllabi.index')->assertOk()->json('syllabi.total');
        $this->assertGreaterThan(0, $current);
        $request('api.v1.teacher.history.index')->assertOk()->assertJsonCount(0, 'careers');

        AcademicPeriod::query()->update(['fecha_fin' => now()->subDay()->toDateString()]);
        $request('api.v1.teacher.syllabi.index')->assertOk()->assertJsonPath('syllabi.total', 0);
        $history = $request('api.v1.teacher.history.index')->assertOk()->assertJsonPath('careers.0.syllabi_count', $current);

        $request('api.v1.teacher.history.show', ['career' => $history->json('careers.0.id')])
            ->assertOk()
            ->assertJsonCount($current, 'syllabi')
            ->assertJsonStructure(['career' => ['id', 'name'], 'syllabi' => [['id', 'subject', 'period', 'state', 'parallels']]]);
    }
}
