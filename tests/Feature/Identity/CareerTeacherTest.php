<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Mail\ManagedUserActivationMail;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Tests\TestCase;

class CareerTeacherTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private RoleAssignment $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->context = $this->coordinator->roleAssignments()->firstOrFail();
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->context->id]);
    }

    private function payload(): array
    {
        return ['nombre' => 'Nuevo Docente', 'correo_electronico' => 'nuevo@silabos.test'];
    }

    public function test_creates_only_a_teacher_in_current_career_and_is_idempotent(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful()->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();
        $this->assertNull($user->correo_verificado_en);
        $this->assertSame(1, $user->roleAssignments()->count());
        $assignment = $user->roleAssignments()->firstOrFail();
        $this->assertSame('docente', $assignment->role->codigo_rol);
        $this->assertSame($this->context->carrera_id, $assignment->carrera_id);
        $this->getJson(route('api.v1.coordination.academic.teacher-assignments.index'))->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('canCreateTeacher', true)
                ->where('career.id', $this->context->carrera_id)
                ->where('options.teacherUsers', fn ($users) => collect($users)->doesntContain('id', $user->id)
                    && collect($users)->contains('id', User::query()->where('correo_electronico', 'docente@silabos.test')->value('id'))));
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => ' NUEVO@silabos.test ', 'nombre' => 'Otro nombre'])->assertSuccessful();
        $this->assertSame($user->nombre, $user->fresh()->nombre);
        $this->assertSame(1, $user->roleAssignments()->count());
        Mail::assertQueued(ManagedUserActivationMail::class, 1);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'usuario.docente_incorporado', 'usuario_id' => $this->coordinator->id, 'recurso_id' => $user->id]);
    }

    public function test_coordinator_can_create_a_teacher_through_the_api_contract(): void
    {
        config(['sanctum.stateful' => ['localhost:5173']]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())
            ->assertOk()
            ->assertJsonPath('message', 'Docente creado para su carrera. Recibirá un enlace de activación por correo.');

        $this->assertDatabaseHas('usuarios', ['correo_electronico' => 'nuevo@silabos.test']);
    }

    public function test_coordinator_can_resend_a_pending_teacher_activation_for_its_career(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();
        $invitation = ActivationInvitation::query()->where('usuario_id', $user->id)->firstOrFail();
        $invitation->update(['expira_en' => now()->subMinute()]);

        $this->postJson(route('api.v1.coordination.academic.teachers.activation.resend', $user))
            ->assertSuccessful()
            ->assertJsonPath('message', 'Invitación de activación enviada a nuevo@silabos.test.');

        $this->assertDatabaseCount('invitaciones_activacion', 2);
        Mail::assertQueued(ManagedUserActivationMail::class, 2);
    }

    public function test_pending_teacher_cannot_be_assigned_until_email_is_verified(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();
        $parallel = Parallel::query()->firstOrFail();

        $this->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
            'user_id' => $user->id,
            'parallel_id' => $parallel->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertFalse(TeacherAssignment::query()
            ->where('asignacion_rol_id', $user->roleAssignments()->firstOrFail()->id)
            ->where('paralelo_id', $parallel->id)
            ->exists());
    }

    public function test_coordinator_can_assign_itself_as_teacher_of_its_career(): void
    {
        $parallel = Parallel::query()->firstOrFail();
        TeacherAssignment::query()->where('paralelo_id', $parallel->id)->update(['activo' => false]);

        $this->getJson(route('api.v1.coordination.academic.teacher-assignments.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $this->coordinator->id, 'email' => 'coordinador@silabos.test']);

        $this->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
            'user_id' => $this->coordinator->id,
            'parallel_id' => $parallel->id,
        ])->assertSuccessful();

        $teacherRole = RoleAssignment::query()->where([
            'usuario_id' => $this->coordinator->id,
            'carrera_id' => $this->context->carrera_id,
            'rol' => RoleCode::Teacher->value,
            'activo' => true,
        ])->firstOrFail();
        $this->assertTrue(TeacherAssignment::query()
            ->where(['asignacion_rol_id' => $teacherRole->id, 'paralelo_id' => $parallel->id, 'activo' => true])
            ->exists());
    }

    public function test_same_teacher_cannot_be_assigned_twice_to_the_same_parallel(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $parallel = Parallel::query()->firstOrFail();
        $assignmentCount = TeacherAssignment::query()
            ->where('paralelo_id', $parallel->id)
            ->where('activo', true)
            ->count();

        $this->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
            'user_id' => $teacher->id,
            'parallel_id' => $parallel->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertSame($assignmentCount, TeacherAssignment::query()
            ->where('paralelo_id', $parallel->id)
            ->where('activo', true)
            ->count());
    }

    public function test_a_parallel_cannot_have_two_active_teachers(): void
    {
        $parallel = Parallel::query()->firstOrFail();
        $career = Career::query()->whereKey($this->context->carrera_id)->firstOrFail();
        $secondTeacher = User::query()->create([
            'nombre' => 'Segundo docente',
            'correo_electronico' => 'segundo.docente@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'correo_verificado_en' => now(),
            'activo' => true,
        ]);
        RoleAssignment::query()->create([
            'usuario_id' => $secondTeacher->id,
            'rol' => RoleCode::Teacher->value,
            'carrera_id' => $career->id,
            'activo' => true,
        ]);

        $this->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
            'user_id' => $secondTeacher->id,
            'parallel_id' => $parallel->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertSame(1, TeacherAssignment::query()
            ->where('paralelo_id', $parallel->id)
            ->where('activo', true)
            ->count());
    }

    public function test_a_previous_inactive_assignment_can_be_reopened_as_new_history(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $role = $teacher->roleAssignments()->where('rol', RoleCode::Teacher->value)->firstOrFail();
        $parallel = Parallel::query()->firstOrFail();
        $previous = TeacherAssignment::query()
            ->where('asignacion_rol_id', $role->id)
            ->where('paralelo_id', $parallel->id)
            ->firstOrFail();
        $previous->update(['activo' => false]);

        $this->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
            'user_id' => $teacher->id,
            'parallel_id' => $parallel->id,
        ])->assertSuccessful();

        $this->assertSame(2, TeacherAssignment::query()
            ->where('asignacion_rol_id', $role->id)
            ->where('paralelo_id', $parallel->id)
            ->count());
        $this->assertSame(1, TeacherAssignment::query()
            ->where('asignacion_rol_id', $role->id)
            ->where('paralelo_id', $parallel->id)
            ->where('activo', true)
            ->count());
    }

    public function test_existing_identity_and_other_roles_are_preserved_without_sending_credentials(): void
    {
        $secondCareer = Career::query()->firstOrFail()->replicate();
        $secondCareer->codigo_carrera = 'SOFTWARE-SEGUNDA';
        $secondCareer->nombre = 'Software Segunda';
        $secondCareer->save();
        $user = User::query()->create([
            'nombre' => 'Segundo Coordinador',
            'correo_electronico' => 'segundo.coordinador@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'correo_verificado_en' => now(),
            'activo' => true,
        ]);
        RoleAssignment::query()->create([
            'usuario_id' => $user->id,
            'rol' => RoleCode::Coordinator->value,
            'carrera_id' => $secondCareer->id,
            'activo' => true,
        ]);
        $original = $user->fresh()->getAttributes();
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => $user->correo_electronico])->assertSuccessful();
        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertSame(2, $user->roleAssignments()->count());
        Mail::assertNothingQueued();
    }

    public function test_an_administrator_cannot_be_incorporated_as_a_teacher(): void
    {
        $administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();

        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [
            ...$this->payload(),
            'correo_electronico' => $administrator->correo_electronico,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('correo_electronico');

        $this->assertSame(1, $administrator->fresh()->roleAssignments()->where('activo', true)->count());
        Mail::assertNothingQueued();
    }

    public function test_cannot_reactivate_disabled_account_but_a_removed_teacher_is_reincorporated(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $teacher->update(['activo' => false]);
        $data = [...$this->payload(), 'correo_electronico' => $teacher->correo_electronico];
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('correo_electronico');
        $this->assertFalse($teacher->fresh()->activo);
        $teacher->update(['activo' => true]);
        $teacher->roleAssignments()->update(['activo' => false]);
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $data)
            ->assertSuccessful()
            ->assertJsonPath('message', 'Docente incorporado a su carrera. Conserva su nombre y acceso actuales.');
        $this->assertTrue($teacher->roleAssignments()->firstOrFail()->activo);
        $this->assertSame(1, $teacher->roleAssignments()->count());
        Mail::assertNothingQueued();
    }

    public function test_coordinator_removes_a_teacher_from_the_career_and_its_parallels_lose_their_teacher(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $assignment = TeacherAssignment::query()->forUser($teacher->id)->firstOrFail();

        $this->deleteJson(route('api.v1.coordination.academic.teachers.destroy', $teacher))
            ->assertSuccessful()
            ->assertJsonPath('message', 'Docente retirado de su carrera; su paralelo quedó sin docente.');

        $this->assertDatabaseMissing('asignaciones_paralelo', ['asignacion_paralelo_id' => $assignment->id]);
        $this->assertFalse($teacher->roleAssignments()->where('rol', RoleCode::Teacher->value)->firstOrFail()->activo);
        $this->assertTrue($teacher->fresh()->activo);
        $teachers = collect($this->getJson(route('api.v1.coordination.academic.teacher-assignments.index'))->assertOk()->json('careerTeachers'));
        $this->assertNull($teachers->firstWhere('id', $teacher->id));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'usuario.docente_retirado', 'recurso_id' => $teacher->id]);

        $this->deleteJson(route('api.v1.coordination.academic.teachers.destroy', $teacher))->assertForbidden();
    }

    public function test_coordinator_cannot_remove_a_teacher_of_another_career(): void
    {
        $other = User::query()->create([
            'nombre' => 'Docente de otra carrera',
            'correo_electronico' => 'otra.carrera@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'correo_verificado_en' => now(),
            'activo' => true,
        ]);
        $career = Career::query()->create([
            'facultad_id' => Career::query()->firstOrFail()->facultad_id,
            'nombre' => 'Turismo',
            'codigo_carrera' => 'TURISMO',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
        RoleAssignment::query()->create(['usuario_id' => $other->id, 'rol' => RoleCode::Teacher->value, 'carrera_id' => $career->id, 'activo' => true]);

        $this->deleteJson(route('api.v1.coordination.academic.teachers.destroy', $other))->assertForbidden();
        $this->assertTrue($other->roleAssignments()->firstOrFail()->activo);
    }

    public function test_cannot_choose_role_career_or_global_account_state(): void
    {
        foreach (['role_code' => 'administrador', 'career_id' => $this->context->carrera_id, 'user_id' => $this->coordinator->id, 'active' => true, 'activo' => true] as $field => $value) {
            $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'nuevo@silabos.test']);
        $this->postJson(route('api.v1.admin.users.store'), [...$this->payload(), 'role_code' => 'administrador'])->assertForbidden();
        Mail::assertNothingQueued();
    }

    public function test_other_active_roles_cannot_use_coordinator_endpoint(): void
    {
        foreach (['admin@silabos.test', 'docente@silabos.test'] as $email) {
            $user = User::query()->where('correo_electronico', $email)->firstOrFail();
            $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
                ->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertForbidden();
        }
        Mail::assertNothingQueued();
    }

    public function test_inactive_career_cannot_create_teachers(): void
    {
        $this->context->career->update(['activo' => false]);
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'nuevo@silabos.test']);
    }

    public function test_invalid_identity_and_client_password_are_rejected(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), ['nombre' => '', 'correo_electronico' => 'invalid', 'password' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'correo_electronico', 'password']);
        Mail::assertNothingQueued();
    }

    public function test_failure_after_account_creation_rolls_back_account_role_and_mail(): void
    {
        $this->partialMock(RecordAuditEvent::class, function ($mock): void {
            $mock->shouldReceive('execute')->withArgs(fn (...$args) => $args[2] === 'usuario.docente_incorporado')
                ->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        });
        $before = RoleAssignment::query()->count();
        $this->withoutExceptionHandling();
        try {
            $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'nuevo@silabos.test']);
        $this->assertSame($before, RoleAssignment::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_historical_inactive_role_does_not_override_current_teacher_access(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $historic = $teacher->roleAssignments()->firstOrFail()->replicate();
        $historic->activo = false;
        $historic->save();
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => $teacher->correo_electronico])->assertSuccessful();
        $this->assertSame(2, $teacher->roleAssignments()->count());
        Mail::assertNothingQueued();
    }

    public function test_teacher_list_includes_every_active_career_teacher_even_without_parallel(): void
    {
        $withoutParallel = User::query()->create([
            'nombre' => 'Docente sin paralelo',
            'correo_electronico' => 'sin.paralelo@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'correo_verificado_en' => now(),
            'activo' => true,
        ]);
        $inactive = User::query()->create([
            'nombre' => 'Docente inactivo',
            'correo_electronico' => 'inactivo@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'correo_verificado_en' => now(),
            'activo' => false,
        ]);
        foreach ([$withoutParallel, $inactive] as $user) {
            RoleAssignment::query()->create([
                'usuario_id' => $user->id,
                'rol' => RoleCode::Teacher->value,
                'carrera_id' => $this->context->carrera_id,
                'activo' => true,
            ]);
        }
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $pendingId = User::query()->where('correo_electronico', 'nuevo@silabos.test')->value('id');

        $teachers = collect($this->getJson(route('api.v1.coordination.academic.teacher-assignments.index'))
            ->assertOk()
            ->json('careerTeachers'));

        $this->assertFalse($teachers->firstWhere('id', $withoutParallel->id)['pending_activation']);
        $this->assertTrue($teachers->firstWhere('id', $pendingId)['pending_activation']);
        $this->assertNotNull($teachers->firstWhere('id', User::query()->where('correo_electronico', 'docente@silabos.test')->value('id')));
        $this->assertNull($teachers->firstWhere('id', $inactive->id));
    }

    public function test_coordinator_corrects_name_and_email_of_a_pending_teacher_and_reissues_the_invitation(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();
        $first = ActivationInvitation::query()->where('usuario_id', $user->id)->firstOrFail();

        $this->patchJson(route('api.v1.coordination.academic.teachers.update', $user), [
            'nombre' => 'docente corregido',
            'correo_electronico' => ' Corregido@silabos.test ',
        ])->assertOk()->assertJsonPath('message', 'Docente actualizado. La invitación anterior se invalidó y se envió una nueva al correo corregido.');

        $user->refresh();
        $this->assertSame('corregido@silabos.test', $user->correo_electronico);
        $this->assertNotSame('docente corregido', $user->nombre);
        $this->assertStringContainsStringIgnoringCase('docente corregido', $user->nombre);
        $this->assertNotNull($first->fresh()->invalidada_en);
        $this->assertDatabaseCount('invitaciones_activacion', 2);
        Mail::assertQueued(ManagedUserActivationMail::class, 2);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'usuario.perfil_actualizado', 'usuario_id' => $this->coordinator->id, 'recurso_id' => $user->id]);
    }

    public function test_coordinator_cannot_change_the_email_of_an_activated_teacher_but_can_fix_the_name(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->patchJson(route('api.v1.coordination.academic.teachers.update', $teacher), [
            'nombre' => $teacher->nombre,
            'correo_electronico' => 'otro@silabos.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('correo_electronico');

        $this->patchJson(route('api.v1.coordination.academic.teachers.update', $teacher), [
            'nombre' => 'Docente Demo Corregido',
            'correo_electronico' => 'docente@silabos.test',
        ])->assertOk()->assertJsonPath('message', 'Docente actualizado.');

        $this->assertSame('DOCENTE DEMO CORREGIDO', $teacher->fresh()->nombre);
        $this->assertSame('docente@silabos.test', $teacher->fresh()->correo_electronico);
        Mail::assertNothingQueued();
    }

    public function test_coordinator_cannot_edit_accounts_outside_its_teachers(): void
    {
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->patchJson(route('api.v1.coordination.academic.teachers.update', $admin), $this->payload())->assertForbidden();

        $outsider = User::query()->create([
            'nombre' => 'Docente Ajeno',
            'correo_electronico' => 'ajeno@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'activo' => true,
        ]);
        $this->patchJson(route('api.v1.coordination.academic.teachers.update', $outsider), $this->payload())->assertForbidden();

        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->patchJson(route('api.v1.coordination.academic.teachers.update', $teacher), [
            'nombre' => $teacher->nombre,
            'correo_electronico' => $teacher->correo_electronico,
            'active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('active');

        $this->patchJson(route('api.v1.coordination.academic.teachers.update', $teacher), [
            'nombre' => $teacher->nombre,
            'correo_electronico' => 'admin@silabos.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('correo_electronico');
        $this->assertTrue($teacher->fresh()->activo);
    }
}
