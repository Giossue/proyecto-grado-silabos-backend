<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CoordinatorAsTeacherTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private RoleAssignment $coordinatorRole;

    private Career $otherCareer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorRole = $this->coordinator->roleAssignments()->firstOrFail();
        $this->otherCareer = Career::query()->create([
            'facultad_id' => Career::query()->firstOrFail()->facultad_id,
            'nombre' => 'Turismo',
            'codigo_carrera' => 'TURISMO',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
    }

    public function test_administration_can_make_a_coordinator_teacher_of_another_career(): void
    {
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();

        $this->actingAs($admin)
            ->withSession(['active_role_assignment_id' => $admin->roleAssignments()->firstOrFail()->id])
            ->patchJson(route('api.v1.admin.users.update', $this->coordinator), [
                'nombre' => $this->coordinator->nombre,
                'correo_electronico' => $this->coordinator->correo_electronico,
                'role_code' => RoleCode::Teacher->value,
                'career_id' => $this->otherCareer->id,
            ])
            ->assertSuccessful();

        $roles = $this->coordinator->roleAssignments()->effective()->get();
        $this->assertTrue($roles->contains(fn (RoleAssignment $role): bool => $role->rol === RoleCode::Coordinator->value && $role->carrera_id === $this->coordinatorRole->carrera_id));
        $this->assertTrue($roles->contains(fn (RoleAssignment $role): bool => $role->rol === RoleCode::Teacher->value && $role->carrera_id === $this->otherCareer->id));
    }

    public function test_the_coordinator_of_another_career_can_add_a_coordinator_as_its_teacher(): void
    {
        $other = User::query()->create([
            'nombre' => 'Coordinador De Turismo',
            'correo_electronico' => 'coordinador.turismo@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'correo_verificado_en' => now(),
            'activo' => true,
        ]);
        $otherRole = RoleAssignment::query()->create(['usuario_id' => $other->id, 'rol' => RoleCode::Coordinator->value, 'carrera_id' => $this->otherCareer->id, 'activo' => true]);

        $this->actingAs($other)
            ->withSession(['active_role_assignment_id' => $otherRole->id])
            ->postJson(route('api.v1.coordination.academic.teachers.store'), [
                'primer_nombre' => 'Ignorado',
                'segundo_nombre' => 'Ignorado',
                'primer_apellido' => 'Ignorado',
                'segundo_apellido' => 'Ignorado',
                'correo_electronico' => $this->coordinator->correo_electronico,
            ])
            ->assertOk();

        $this->assertTrue($this->coordinator->roleAssignments()->effective()
            ->where('rol', RoleCode::Teacher->value)
            ->where('carrera_id', $this->otherCareer->id)
            ->exists());
        $this->assertTrue($this->coordinator->roleAssignments()->effective()
            ->where('rol', RoleCode::Coordinator->value)
            ->where('carrera_id', $this->coordinatorRole->carrera_id)
            ->exists());
    }
}
