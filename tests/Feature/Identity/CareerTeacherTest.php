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
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class CareerTeacherTest extends TestCase
{
    use CreatesSyllabusProcess;
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
        return ['primer_nombre' => 'Nuevo', 'segundo_nombre' => 'Luis', 'primer_apellido' => 'Docente', 'segundo_apellido' => 'Prueba', 'correo_electronico' => 'nuevo@silabos.test'];
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
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => ' NUEVO@silabos.test ', 'primer_nombre' => 'Otro'])->assertSuccessful();
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

    public function test_adding_a_pending_teacher_again_sends_a_new_link_when_the_previous_one_expired(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();
        ActivationInvitation::query()->where('usuario_id', $user->id)->firstOrFail()->update(['expira_en' => now()->subMinute()]);

        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())
            ->assertSuccessful()
            ->assertJsonPath('message', 'El docente ya está disponible en su carrera. Conserva su nombre y acceso actuales.');

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

    public function test_removing_a_teacher_who_has_not_verified_cancels_the_pending_invitation(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();
        $invitation = ActivationInvitation::query()->where('usuario_id', $user->id)->firstOrFail();
        $this->assertNull($invitation->invalidada_en);

        $this->deleteJson(route('api.v1.coordination.academic.teachers.destroy', $user))->assertSuccessful();

        $this->assertNotNull($invitation->fresh()->invalidada_en);
        $this->assertNull($user->fresh()->correo_verificado_en);
    }

    public function test_a_teacher_cannot_be_removed_from_the_career_while_its_convocation_is_open(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $assignment = TeacherAssignment::query()->forUser($teacher->id)->firstOrFail();
        Convocation::query()->create([
            'carrera_id' => $this->context->carrera_id,
            'proceso_id' => $this->openSyllabusProcess('')->id,
            'estado' => Convocation::STATE_OPEN,
        ]);

        $this->deleteJson(route('api.v1.coordination.academic.teachers.destroy', $teacher))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('process');

        $this->assertDatabaseHas('asignaciones_paralelo', ['asignacion_paralelo_id' => $assignment->id]);
        $this->assertTrue($teacher->roleAssignments()->where('rol', RoleCode::Teacher->value)->firstOrFail()->activo);
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

    public function test_the_full_name_is_composed_from_the_four_parts_and_a_single_field_is_rejected(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $this->assertSame('NUEVO LUIS DOCENTE PRUEBA', User::query()->where('correo_electronico', 'nuevo@silabos.test')->value('nombre'));

        $this->postJson(route('api.v1.coordination.academic.teachers.store'), ['nombre' => 'Un Nombre Completo', 'correo_electronico' => 'otro@silabos.test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido']);
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => 'otro@silabos.test', 'segundo_apellido' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('segundo_apellido');
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'otro@silabos.test']);
    }

    public function test_invalid_identity_and_client_password_are_rejected(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), ['primer_nombre' => '', 'segundo_nombre' => '', 'primer_apellido' => '', 'segundo_apellido' => '', 'correo_electronico' => 'invalid', 'password' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'correo_electronico', 'password']);
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

    public function test_coordinator_only_adds_a_pending_account_and_cannot_edit_reinvite_or_delete_it(): void
    {
        $otherCareer = Career::query()->create([
            'facultad_id' => Career::query()->firstOrFail()->facultad_id,
            'nombre' => 'Turismo',
            'codigo_carrera' => 'TURISMO',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);
        $pending = User::query()->create([
            'nombre' => 'Coordinador Pendiente De Turismo',
            'correo_electronico' => 'pendiente.turismo@silabos.test',
            'contrasena' => Hash::make('Original-2026!'),
            'activo' => true,
        ]);
        RoleAssignment::query()->create(['usuario_id' => $pending->id, 'rol' => RoleCode::Coordinator->value, 'carrera_id' => $otherCareer->id, 'activo' => true]);
        $original = $pending->fresh()->getAttributes();

        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => $pending->correo_electronico])
            ->assertOk()
            ->assertJsonPath('message', 'Docente incorporado a su carrera. Conserva su nombre y acceso actuales.');
        $this->assertTrue($pending->roleAssignments()->effective()->where(['rol' => RoleCode::Teacher->value, 'carrera_id' => $this->context->carrera_id])->exists());
        Mail::assertQueued(ManagedUserActivationMail::class, fn (ManagedUserActivationMail $mail): bool => $mail->hasTo('pendiente.turismo@silabos.test'));

        $this->patchJson("/api/v1/coordination/academic/teachers/{$pending->id}", [
            'nombre' => 'Otra Persona Con Nombre',
            'correo_electronico' => 'atacante@silabos.test',
        ])->assertMethodNotAllowed();
        $this->postJson(route('api.v1.coordination.academic.teachers.activation.resend', $pending))
            ->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->postJson(route('api.v1.admin.users.activation.resend', $pending))->assertForbidden();
        $this->deleteJson(route('api.v1.admin.users.destroy', $pending))->assertForbidden();

        $this->assertSame($original, $pending->fresh()->getAttributes());
        $this->assertTrue(Hash::check('Original-2026!', $pending->fresh()->contrasena));
        $this->assertNull($pending->fresh()->correo_verificado_en);
        $this->assertTrue($pending->roleAssignments()->effective()->where('rol', RoleCode::Coordinator->value)->exists());
        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'atacante@silabos.test']);
    }

    public function test_the_coordinator_resends_the_invitation_only_once_it_expired_without_being_accepted(): void
    {
        Mail::fake();
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();
        $listed = fn (): array => collect($this->getJson(route('api.v1.coordination.academic.teacher-assignments.index'))->assertOk()->json('careerTeachers'))->firstWhere('id', $user->id);

        $this->assertFalse($listed()['invitation_expired']);
        $this->postJson(route('api.v1.coordination.academic.teachers.activation.resend', $user))
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', 'La invitación sigue vigente; podrá reenviarla cuando venza.');
        $this->assertDatabaseCount('invitaciones_activacion', 1);

        ActivationInvitation::query()->where('usuario_id', $user->id)->update(['expira_en' => now()->subMinute()]);
        $this->assertTrue($listed()['invitation_expired']);
        $this->postJson(route('api.v1.coordination.academic.teachers.activation.resend', $user))
            ->assertOk()
            ->assertJsonPath('message', 'Invitación de activación enviada a nuevo@silabos.test.');

        $this->assertDatabaseCount('invitaciones_activacion', 2);
        Mail::assertQueued(ManagedUserActivationMail::class, fn (ManagedUserActivationMail $mail): bool => $mail->hasTo('nuevo@silabos.test'));
        $this->assertFalse($listed()['invitation_expired']);
    }

    public function test_the_coordinator_cannot_resend_to_someone_outside_its_career_or_already_active(): void
    {
        $outsider = User::query()->create(['nombre' => 'Docente De Otra Carrera', 'correo_electronico' => 'otra.carrera@silabos.test', 'contrasena' => Hash::make('Original-2026!'), 'activo' => true]);
        $this->postJson(route('api.v1.coordination.academic.teachers.activation.resend', $outsider))->assertForbidden();

        $active = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->postJson(route('api.v1.coordination.academic.teachers.activation.resend', $active))->assertForbidden();
        $this->assertDatabaseMissing('invitaciones_activacion', ['usuario_id' => $outsider->id]);
    }

    public function test_coordinator_cannot_delete_a_pending_teacher_it_created(): void
    {
        $this->postJson(route('api.v1.coordination.academic.teachers.store'), $this->payload())->assertSuccessful();
        $user = User::query()->where('correo_electronico', 'nuevo@silabos.test')->firstOrFail();

        $this->deleteJson(route('api.v1.admin.users.destroy', $user))->assertForbidden();
        $this->postJson(route('api.v1.admin.users.activation.resend', $user))->assertForbidden();

        $this->assertDatabaseHas('usuarios', ['usuario_id' => $user->id]);
        $this->assertDatabaseCount('invitaciones_activacion', 1);
    }

    public function test_a_librarian_cannot_be_incorporated_as_a_teacher(): void
    {
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();

        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => $librarian->correo_electronico])
            ->assertUnprocessable()
            ->assertJsonPath('errors.correo_electronico.0', 'No se puede incorporar como docente una cuenta con el rol Bibliotecario.');

        $this->assertSame([RoleCode::Librarian->value], $librarian->roleAssignments()->effective()->pluck('rol')->all());
        Mail::assertNothingQueued();
    }

    public function test_coordinator_adds_a_verified_teacher_of_another_career_and_can_remove_it_from_its_own(): void
    {
        $other = User::query()->create([
            'nombre' => 'Docente Compartido Entre Carreras',
            'correo_electronico' => 'compartido@silabos.test',
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
        $original = $other->fresh()->getAttributes();

        $this->postJson(route('api.v1.coordination.academic.teachers.store'), [...$this->payload(), 'correo_electronico' => $other->correo_electronico])
            ->assertOk()
            ->assertJsonPath('message', 'Docente incorporado a su carrera. Conserva su nombre y acceso actuales.');
        $this->assertSame($original, $other->fresh()->getAttributes());
        $this->assertSame(2, $other->roleAssignments()->effective()->count());
        Mail::assertNothingQueued();

        $this->deleteJson(route('api.v1.coordination.academic.teachers.destroy', $other))->assertOk();
        $this->assertSame([$career->id], $other->roleAssignments()->effective()->pluck('carrera_id')->all());
    }
}
