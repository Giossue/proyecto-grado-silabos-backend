<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSyllabiIndexTest extends TestCase
{
    use RefreshDatabase;

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
}
