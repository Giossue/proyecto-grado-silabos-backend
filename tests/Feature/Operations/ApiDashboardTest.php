<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173']]);
    }

    public function test_teacher_receives_only_its_dashboard_metrics_and_setup(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonCount(2, 'metrics')
            ->assertJsonPath('metrics.0.key', 'pending')
            ->assertJsonPath('setup.title', 'Sus sílabos, paso a paso')
            ->assertJsonCount(3, 'setup.steps')
            ->assertJsonPath('deadline', null)
            ->assertJsonPath('coordination_convocation', null)
            ->assertJsonMissingPath('faculty_logo')
            ->assertJsonMissingPath('setup.steps.0.href');
    }

    public function test_coordinator_dashboard_does_not_offer_faculty_logo_management(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();

        $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $coordinator->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonCount(3, 'metrics')
            ->assertJsonPath('deadline', null)
            ->assertJsonPath('coordination_convocation', null)
            ->assertJsonMissingPath('faculty_logo')
            ->assertJsonMissing(['key' => 'faculty_logo'])
            ->assertJsonCount(6, 'setup.steps');
    }

    public function test_coordinator_dashboard_uses_the_current_period_and_requires_every_parallel_to_have_a_teacher(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();
        $template = app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => $scheduledSubject->periodo_academico_id,
            'inicia_en' => now()->addDays(2),
            'entrega_en' => now()->addDays(9),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        Parallel::query()->create([
            'programacion_asignatura_id' => $scheduledSubject->id,
            'codigo' => 'B',
        ]);

        $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $coordinator->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonCount(3, 'metrics')
            ->assertJsonPath('deadline.state', 'upcoming')
            ->assertJsonPath('deadline.days_until_start', 2)
            ->assertJsonPath('deadline.days_until_due', 9)
            ->assertJsonPath('deadline.convocation_id', null)
            ->assertJsonPath('coordination_convocation.process_id', $process->id)
            ->assertJsonPath('coordination_convocation.can_open', false)
            ->assertJsonPath('setup.steps.3.key', 'teachers')
            ->assertJsonPath('setup.steps.3.done', false)
            ->assertJsonPath('setup.steps.3.hint', '1 de 2 paralelos tienen un docente vigente.')
            ->assertJsonPath('setup.steps.5.action', null);
    }

    public function test_dashboard_requires_an_active_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();

        $this->actingAs($coordinator)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertConflict();
    }
}
