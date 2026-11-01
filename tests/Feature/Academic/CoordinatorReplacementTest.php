<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CoordinatorAssignment;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Tests\TestCase;

class CoordinatorReplacementTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
    }

    public function test_the_careers_table_shows_who_coordinates_today(): void
    {
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.academic.index', 'carreras'))
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('catalogs.careers.0.coordinator.id', $this->coordinator->id));
    }

    public function test_assigning_a_coordinator_to_a_coordinated_career_asks_to_replace_and_then_replaces(): void
    {
        $incoming = $this->activeTeacher('entrante@silabos.test');
        $payload = ['nombre' => $incoming->nombre, 'correo_electronico' => $incoming->correo_electronico, 'role_code' => RoleCode::Coordinator->value, 'career_id' => $this->career->id];

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.users.update', $incoming), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replace_coordinator' => "La carrera ya tiene como coordinador a {$this->coordinator->nombre}."]);
        $this->assertDatabaseHas('asignaciones_rol', ['usuario_id' => $this->coordinator->id, 'rol' => RoleCode::Coordinator->value, 'asignacion_rol_activa' => true]);

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.users.update', $incoming), [...$payload, 'replace_coordinator' => true])->assertOk();

        $this->assertTrue(CoordinatorAssignment::query()->effective()->where('carrera_id', $this->career->id)->where('usuario_id', $incoming->id)->exists());
        $this->assertDatabaseHas('asignaciones_rol', ['usuario_id' => $this->coordinator->id, 'rol' => RoleCode::Coordinator->value, 'carrera_id' => $this->career->id, 'asignacion_rol_activa' => false]);
        $this->assertTrue($this->coordinator->fresh()->activo);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.coordinacion.reemplazada', 'recurso_id' => $this->career->id]);
    }

    public function test_a_career_without_coordination_gets_one_without_confirmation(): void
    {
        CoordinatorAssignment::query()->where('carrera_id', $this->career->id)->update(['activo' => false]);
        $incoming = $this->activeTeacher('primera@silabos.test');

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.users.update', $incoming), [
            'nombre' => $incoming->nombre, 'correo_electronico' => $incoming->correo_electronico,
            'role_code' => RoleCode::Coordinator->value, 'career_id' => $this->career->id,
        ])->assertOk();

        $this->assertTrue(CoordinatorAssignment::query()->effective()->where('carrera_id', $this->career->id)->where('usuario_id', $incoming->id)->exists());
    }

    private function activeTeacher(string $email): User
    {
        $user = User::query()->create(['nombre' => 'Docente Entrante', 'correo_electronico' => $email, 'contrasena' => 'Temporal-2026!', 'activo' => true]);
        RoleAssignment::query()->create([
            'usuario_id' => $user->id,
            'rol' => RoleCode::Teacher->value,
            'carrera_id' => $this->career->id,
            'activo' => true,
        ]);

        return $user;
    }

    private function actingAsAdministrator(): static
    {
        return $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administratorContext->id]);
    }
}
