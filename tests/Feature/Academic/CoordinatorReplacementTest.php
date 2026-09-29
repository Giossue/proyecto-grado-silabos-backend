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
use Tests\Support\AssertsInternalNotifications;
use Tests\TestCase;

class CoordinatorReplacementTest extends TestCase
{
    use AssertsInternalNotifications;
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
                ->where('catalogs.careers.0.coordinator.id', $this->coordinator->id)
                ->has('options.coordinatorUsers'));
    }

    public function test_replacing_the_coordinator_closes_the_outgoing_mandate_and_role_and_opens_the_incoming_ones(): void
    {
        $incoming = $this->activeTeacher('entrante@silabos.test');

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.careers.coordinator.replace', $this->career), ['incoming_user_id' => $incoming->id])
            ->assertSuccessful()
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertTrue(CoordinatorAssignment::query()->effective()->where('carrera_id', $this->career->id)->where('usuario_id', $incoming->id)->exists());
        $this->assertDatabaseHas('asignaciones_rol', ['usuario_id' => $this->coordinator->id, 'rol' => RoleCode::Coordinator->value, 'carrera_id' => $this->career->id, 'asignacion_rol_activa' => false]);
        $this->assertDatabaseHas('asignaciones_rol', ['usuario_id' => $incoming->id, 'rol' => RoleCode::Coordinator->value, 'carrera_id' => $this->career->id, 'asignacion_rol_activa' => true]);
        $this->assertSame(2, RoleAssignment::query()->effective()->where('usuario_id', $incoming->id)->count());
        $this->assertTrue($this->coordinator->fresh()->activo);
        $this->assertDatabaseHas('eventos_auditoria', ['accion_evento_auditoria' => 'academico.coordinacion.reemplazada', 'recurso_id' => $this->career->id]);
        $this->assertNotified($incoming->id, 'coordinacion.asignada', "Se le asignó la coordinación de {$this->career->nombre}.");
        $this->assertNotNotified($incoming->id, 'usuario.rol_asignado');

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.careers.coordinator.replace', $this->career), ['incoming_user_id' => $incoming->id])
            ->assertJsonValidationErrors('incoming_user_id');
    }

    public function test_coordinator_candidates_exclude_only_administration(): void
    {
        $pending = $this->activeTeacher('pendiente@silabos.test');
        $activated = $this->activeTeacher('activada@silabos.test');
        $activated->markEmailAsVerified();

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.academic.index', 'carreras'))
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('options.coordinatorUsers', fn ($users) => collect($users)->pluck('id')->contains($activated->id)
                    && collect($users)->pluck('id')->contains($pending->id)
                    && ! collect($users)->pluck('id')->contains($this->administrator->id)));
    }

    public function test_administration_cannot_take_a_coordination(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.careers.coordinator.replace', $this->career), ['incoming_user_id' => $this->administrator->id])
            ->assertUnprocessable();
    }

    public function test_a_career_without_coordination_gets_one_assigned_with_the_same_action(): void
    {
        CoordinatorAssignment::query()->where('carrera_id', $this->career->id)->update(['activo' => false]);
        $incoming = $this->activeTeacher('primera@silabos.test');

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.academic.careers.coordinator.replace', $this->career), ['incoming_user_id' => $incoming->id])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertTrue(CoordinatorAssignment::query()->effective()->where('carrera_id', $this->career->id)->where('usuario_id', $incoming->id)->exists());
    }

    public function test_only_administration_replaces_coordinators(): void
    {
        $incoming = $this->activeTeacher('entrante@silabos.test');
        $context = $this->coordinator->roleAssignments()->firstOrFail();

        $this->actingAs($this->coordinator)
            ->withSession(['active_role_assignment_id' => $context->id])
            ->postJson(route('api.v1.admin.academic.careers.coordinator.replace', $this->career), ['incoming_user_id' => $incoming->id])
            ->assertForbidden();
    }

    private function activeTeacher(string $email): User
    {
        $user = User::query()->create(['nombre' => 'Docente '.$email, 'correo_electronico' => $email, 'contrasena' => 'Temporal-2026!', 'activo' => true]);
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
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }
}
