<?php

namespace Tests\Feature\Operations;

use App\Models\User;
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
            ->assertJsonCount(3, 'metrics')
            ->assertJsonPath('metrics.0.key', 'pending')
            ->assertJsonPath('setup.title', 'Sus sílabos, paso a paso')
            ->assertJsonCount(3, 'setup.steps')
            ->assertJsonPath('faculty_logo', null)
            ->assertJsonMissingPath('setup.steps.0.href');
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
