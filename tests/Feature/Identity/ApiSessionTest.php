<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173']]);
    }

    public function test_authenticated_teacher_receives_a_session_payload_and_sole_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $assignment = $teacher->roleAssignments()->firstOrFail();

        $this->actingAs($teacher)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.session.show'))
            ->assertOk()
            ->assertJsonPath('user.id', $teacher->id)
            ->assertJsonPath('user.name', $teacher->nombre)
            ->assertJsonPath('user.email', $teacher->correo_electronico)
            ->assertJsonPath('active_role.id', $assignment->id)
            ->assertJsonPath('active_role.code', 'docente')
            ->assertJsonCount(1, 'available_roles');
    }

    public function test_login_returns_the_authenticated_session_without_exposing_the_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.authentication.login'), [
                'email' => $teacher->correo_electronico,
                'password' => 'Demo-2026!',
            ])
            ->assertOk()
            ->assertJsonPath('user.id', $teacher->id)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.contrasena');

        $this->assertAuthenticatedAs($teacher);
    }

    public function test_coordinator_must_select_a_career_explicitly(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $assignment = $coordinator->roleAssignments()->firstOrFail();

        $this->actingAs($coordinator)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.session.show'))
            ->assertOk()
            ->assertJsonPath('active_role', null)
            ->assertJsonPath('available_roles.0.id', $assignment->id);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->putJson(route('api.v1.active-role.update'), ['role_assignment_id' => $assignment->id])
            ->assertOk()
            ->assertJsonPath('active_role.id', $assignment->id)
            ->assertJsonPath('active_role.code', 'coordinador');
    }
}
