<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CoordinatorAssignment;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\Actions\SetUserStatus;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Mail\ManagedUserActivationMail;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Tests\Support\AssertsInternalNotifications;
use Tests\TestCase;

class ManagedUserTest extends TestCase
{
    use AssertsInternalNotifications;
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
    }

    public function test_administrator_can_list_and_filter_managed_users(): void
    {
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['status' => 'all', 'q' => 'Docente']))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()

                ->has('users.data', 1)
                ->where('users.data.0.correo_electronico', 'docente@silabos.test')
                ->where('users.data.0.pending_activation', false)
                ->where('filters.q', 'Docente')
                ->where('filters.status', null));
    }

    public function test_administrator_enters_without_selecting_its_only_role(): void
    {
        $this->actingAs($this->administrator)
            ->getJson(route('api.v1.admin.users.index'))
            ->assertOk();
    }

    public function test_administration_is_denied_without_the_administrator_role(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->getJson(route('api.v1.admin.users.index'))
            ->assertForbidden();
    }

    public function test_the_list_filters_by_role_career_and_state(): void
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['role' => RoleCode::Coordinator->value]))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->has('users.data', 1)
                ->where('users.data.0.correo_electronico', 'coordinador@silabos.test')
                ->where('filters.role', RoleCode::Coordinator->value));

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['career' => $career->id]))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()->has('users.data', 2));

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['status' => 'pending']))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()->has('users.data', 0));

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.users.store'), [
                'nombre' => 'Docente Sin Estrenar',
                'correo_electronico' => 'sin.estrenar@silabos.test',
                'role_code' => RoleCode::Teacher->value,
                'career_id' => $career->id,
            ])->assertSuccessful();

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['status' => 'pending']))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->has('users.data', 1)
                ->where('users.data.0.correo_electronico', 'sin.estrenar@silabos.test'));

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['status' => 'active']))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()->has('users.data', 4));
    }

    public function test_the_list_separates_the_role_from_its_career(): void
    {
        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['q' => 'Administrador']))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->where('users.data.0.roles.0.name', 'Administrador')
                ->where('users.data.0.careers.0', null));
    }

    public function test_the_list_carries_the_read_only_file_of_each_account(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $archived = $teacher->roleAssignments()->firstOrFail();
        $archived->update(['activo' => false]);

        $this->actingAsAdministrator()
            ->getJson(route('api.v1.admin.users.index', ['q' => 'docente@silabos.test']))
            ->assertOk()
            ->assertJson(fn (Assert $page) => $page->etc()
                ->has('users.data', 1)
                ->missing('users.data.0.created_at')
                ->where('users.data.0.two_factor_enabled', false)
                ->has('users.data.0.roles', 0)
                ->has('users.data.0.assignments', 1)
                ->where('users.data.0.assignments.0.id', $archived->id)
                ->where('users.data.0.assignments.0.active', false));
    }

    public function test_teacher_cannot_access_user_management_even_with_a_valid_context(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $teacherContext = $teacher->roleAssignments()->firstOrFail();

        $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacherContext->id])
            ->getJson(route('api.v1.admin.users.index'))
            ->assertForbidden();
    }

    public function test_administrator_creates_user_with_scoped_role_and_audit_event(): void
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.users.store'), [
                'nombre' => 'Nueva Docente',
                'correo_electronico' => 'nueva.docente@silabos.test',
                'role_code' => RoleCode::Teacher->value,
                'career_id' => $career->id,
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $created = User::query()->where('correo_electronico', 'nueva.docente@silabos.test')->firstOrFail();
        $this->assertDatabaseHas('asignaciones_rol', [
            'usuario_id' => $created->id,
            'rol' => RoleCode::Teacher->value,
            'carrera_id' => $career->id,
            'asignacion_rol_activa' => true,
        ]);
        $this->assertDatabaseHas('eventos_auditoria', [
            'usuario_id' => $this->administrator->id,
            'asignacion_rol_id' => $this->administratorContext->id,
            'accion' => 'usuario.creado',
            'recurso_id' => $created->id,
            'resultado' => 'exito',
        ]);
    }

    public function test_scoped_role_requires_an_active_career(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.users.store'), [
                'nombre' => 'Docente sin carrera',
                'correo_electronico' => 'sin.carrera@silabos.test',
                'role_code' => RoleCode::Teacher->value,
            ])
            ->assertJsonValidationErrors('career_id');

        $this->assertDatabaseMissing('usuarios', ['correo_electronico' => 'sin.carrera@silabos.test']);
    }

    public function test_granting_a_coordination_opens_its_mandate(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $teacher), [
                ...$this->identityOf($teacher),
                'role_code' => RoleCode::Coordinator->value,
                'career_id' => $career->id,
            ])
            ->assertJsonValidationErrors('role_code');

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $this->coordinatorHolder()), [...$this->identityOf($this->coordinatorHolder()), 'active' => false])
            ->assertSuccessful();

        $this->assertFalse(CoordinatorAssignment::query()
            ->where('usuario_id', $this->coordinatorHolder()->id)
            ->where('activo', true)
            ->exists());
        $this->assertFalse(CoordinatorAssignment::query()->effective()->where('carrera_id', $career->id)->exists());
        $event = DB::table('eventos_auditoria')
            ->where('accion', 'usuario.desactivado')
            ->where('recurso_id', $this->coordinatorHolder()->id)
            ->firstOrFail();
        $this->assertStringContainsString($career->nombre, (string) json_encode($event));

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $teacher), [
                ...$this->identityOf($teacher),
                'role_code' => RoleCode::Coordinator->value,
                'career_id' => $career->id,
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertTrue(CoordinatorAssignment::query()
            ->effective()
            ->where('usuario_id', $teacher->id)
            ->where('carrera_id', $career->id)
            ->exists());
        $this->assertNotNotified($teacher->id, 'usuario.rol_asignado');
    }

    private function coordinatorHolder(): User
    {
        return User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
    }

    public function test_administrator_assigns_an_additional_role_without_overwriting_history(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        $previousAssignmentId = $teacher->roleAssignments()->firstOrFail()->id;

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $this->coordinatorHolder()), [...$this->identityOf($this->coordinatorHolder()), 'active' => false])
            ->assertSuccessful();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $teacher), [
                ...$this->identityOf($teacher),
                'role_code' => RoleCode::Coordinator->value,
                'career_id' => $career->id,
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertDatabaseHas('asignaciones_rol', ['asignacion_rol_id' => $previousAssignmentId, 'asignacion_rol_activa' => true]);
        $this->assertDatabaseHas('asignaciones_rol', [
            'usuario_id' => $teacher->id,
            'rol' => RoleCode::Coordinator->value,
            'carrera_id' => $career->id,
            'asignacion_rol_activa' => true,
        ]);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'usuario.rol_asignado',
            'recurso_id' => $teacher->id,
        ]);
    }

    public function test_administrator_cannot_receive_another_active_role(): void
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        $target = User::query()->create([
            'nombre' => 'Segundo Administrador',
            'correo_electronico' => 'segundo.admin@silabos.test',
            'contrasena' => Hash::make('Demo-2026!'),
            'activo' => true,
        ]);
        RoleAssignment::query()->create([
            'usuario_id' => $target->id,
            'rol' => RoleCode::Administrator->value,
            'carrera_id' => null,
            'activo' => true,
        ]);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $target), [
                ...$this->identityOf($target),
                'role_code' => RoleCode::Teacher->value,
                'career_id' => $career->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_code');

        $this->assertSame(1, $target->fresh()->roleAssignments()->where('activo', true)->count());
        $this->assertDatabaseMissing('asignaciones_rol', [
            'usuario_id' => $target->id,
            'rol' => RoleCode::Teacher->value,
            'carrera_id' => $career->id,
            'asignacion_rol_activa' => true,
        ]);
    }

    public function test_administrator_role_cannot_be_added_to_a_user_with_another_active_role(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $teacher), [
                ...$this->identityOf($teacher),
                'role_code' => RoleCode::Administrator->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_code');

        $this->assertDatabaseMissing('asignaciones_rol', [
            'usuario_id' => $teacher->id,
            'rol' => RoleCode::Administrator->value,
            'asignacion_rol_activa' => true,
        ]);
    }

    public function test_administrator_can_assign_the_same_coordinator_to_another_career(): void
    {
        $coordinator = $this->coordinatorHolder();
        $originalAssignment = $coordinator->roleAssignments()->firstOrFail();
        $secondCareer = Career::query()->create([
            'facultad_id' => Career::query()->firstOrFail()->facultad_id,
            'codigo_carrera' => 'CARR-SEGUNDA-COORDINACION',
            'nombre' => 'Segunda carrera coordinada',
            'cantidad_ciclos_malla' => 8,
            'activo' => true,
        ]);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $coordinator), [
                ...$this->identityOf($coordinator),
                'role_code' => RoleCode::Coordinator->value,
                'career_id' => $secondCareer->id,
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertDatabaseHas('asignaciones_rol', [
            'asignacion_rol_id' => $originalAssignment->id,
            'asignacion_rol_activa' => true,
        ]);
        $this->assertDatabaseHas('asignaciones_rol', [
            'usuario_id' => $coordinator->id,
            'carrera_id' => $secondCareer->id,
            'asignacion_rol_activa' => true,
        ]);
        $this->assertTrue(CoordinatorAssignment::query()
            ->effective()
            ->where('usuario_id', $coordinator->id)
            ->where('carrera_id', $secondCareer->id)
            ->exists());
        $this->assertCount(2, $coordinator->fresh()->roleAssignments);
    }

    public function test_deactivating_user_revokes_sessions_but_preserves_roles_and_audits(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $roleAssignmentId = $teacher->roleAssignments()->firstOrFail()->id;
        DB::table('sesiones')->insert([
            'id' => 'teacher-session-to-revoke',
            'user_id' => $teacher->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature test',
            'payload' => 'synthetic-session-payload',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $teacher), [...$this->identityOf($teacher), 'active' => false])
            ->assertSuccessful()
            ->assertSuccessful();

        $this->assertDatabaseHas('usuarios', ['usuario_id' => $teacher->id, 'usuario_activo' => false]);
        $this->assertDatabaseMissing('sesiones', ['id' => 'teacher-session-to-revoke']);
        $this->assertDatabaseHas('asignaciones_rol', ['asignacion_rol_id' => $roleAssignmentId]);
        $this->assertDatabaseHas('eventos_auditoria', [
            'accion' => 'usuario.desactivado',
            'recurso_id' => $teacher->id,
            'resultado' => 'exito',
        ]);
    }

    public function test_administrator_cannot_deactivate_own_account(): void
    {
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $this->administrator), [...$this->identityOf($this->administrator), 'active' => false])
            ->assertForbidden();

        $this->assertTrue($this->administrator->fresh()->activo);
    }

    public function test_a_pending_account_gets_a_new_activation_invitation(): void
    {
        Mail::fake();
        $pending = $this->pendingTeacher();
        $previous = ActivationInvitation::query()->where('usuario_id', $pending->id)->firstOrFail();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.users.activation.resend', $pending))
            ->assertSuccessful()
            ->assertSuccessful();

        $invitations = ActivationInvitation::query()->where('usuario_id', $pending->id)->orderBy('creada_en')->orderBy('id')->get();
        $previous = $invitations->firstOrFail();
        $current = $invitations->last();
        $this->assertCount(2, $invitations);
        $this->assertNotSame($previous->id, $current->id);
        $this->assertNotNull($previous->invalidada_en);
        $this->assertNull($current->usada_en);
        Mail::assertQueuedCount(2);
        Mail::assertQueued(ManagedUserActivationMail::class, fn (ManagedUserActivationMail $mail): bool => $mail->hasTo('pendiente@silabos.test'));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'usuario.invitacion_activacion_enviada', 'recurso_id' => $pending->id]);

        $activationUrl = '';
        Mail::assertQueued(ManagedUserActivationMail::class, function (ManagedUserActivationMail $mail) use (&$activationUrl): bool {
            $activationUrl = $mail->activationUrl;

            return $mail->hasTo('pendiente@silabos.test');
        });
        parse_str((string) parse_url($activationUrl, PHP_URL_QUERY), $query);
        $this->postJson(route('api.v1.authentication.account-activation'), [
            'token' => $query['token'],
            'password' => 'Nueva-clave-2026!',
            'password_confirmation' => 'Nueva-clave-2026!',
        ])->assertOk();
        $this->assertNotNotified($this->administrator->id, 'usuario.activado');
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.users.activation.resend', $pending))
            ->assertForbidden();
    }

    public function test_correcting_the_email_of_a_pending_account_resends_the_access_to_the_new_address(): void
    {
        Mail::fake();
        $pending = $this->pendingTeacher();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $pending), [
                'nombre' => $pending->nombre,
                'correo_electronico' => 'corregido@silabos.test',
            ])
            ->assertSuccessful()
            ->assertSuccessful();

        Mail::assertQueued(ManagedUserActivationMail::class, fn (ManagedUserActivationMail $mail): bool => $mail->hasTo('corregido@silabos.test'));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'usuario.invitacion_activacion_enviada', 'recurso_id' => $pending->id]);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $pending), [
                'nombre' => 'Nombre Corregido',
                'correo_electronico' => 'corregido@silabos.test',
            ])
            ->assertSuccessful();
        Mail::assertQueuedCount(2);
    }

    public function test_a_pending_account_without_activity_can_be_deleted_but_a_used_one_is_archived(): void
    {
        $pending = $this->pendingTeacher();

        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.users.destroy', $pending))
            ->assertSuccessful();
        $this->assertDatabaseMissing('usuarios', ['usuario_id' => $pending->id]);
        $this->assertDatabaseMissing('asignaciones_rol', ['usuario_id' => $pending->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'usuario.eliminado', 'recurso_id' => $pending->id]);

        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.users.destroy', $teacher))
            ->assertForbidden();
        $this->assertDatabaseHas('usuarios', ['usuario_id' => $teacher->id]);

        $assigned = $this->pendingTeacher('asignado@silabos.test');
        $base = Parallel::query()->firstOrFail();
        $parallel = Parallel::query()->create([
            'periodo_academico_id' => $base->periodo_academico_id,
            'asignatura_id' => $base->asignatura_id,
            'codigo' => 'Z',
            'jornada' => 'matutina',
        ]);
        TeacherAssignment::query()->create([
            'usuario_id' => $assigned->id,
            'paralelo_id' => $parallel->id,
            'activo' => true,
        ]);
        $this->actingAsAdministrator()
            ->deleteJson(route('api.v1.admin.users.destroy', $assigned))
            ->assertJsonValidationErrors('user');
        $this->assertDatabaseHas('usuarios', ['usuario_id' => $assigned->id]);
    }

    private function pendingTeacher(string $email = 'pendiente@silabos.test'): User
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.users.store'), [
                'nombre' => 'Docente Pendiente',
                'correo_electronico' => $email,
                'role_code' => RoleCode::Teacher->value,
                'career_id' => $career->id,
            ])
            ->assertSuccessful();

        return User::query()->where('correo_electronico', $email)->firstOrFail();
    }

    public function test_the_last_administrator_cannot_be_archived_and_archiving_closes_teacher_assignments(): void
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        $secondAdmin = User::query()->create(['nombre' => 'Segunda Admin', 'correo_electronico' => 'admin2@silabos.test', 'contrasena' => 'Temporal-2026!', 'activo' => true]);
        RoleAssignment::query()->create([
            'usuario_id' => $secondAdmin->id,
            'rol' => RoleCode::Administrator->value,
            'carrera_id' => null,
            'activo' => true,
        ]);

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $secondAdmin), ['nombre' => $secondAdmin->nombre, 'correo_electronico' => $secondAdmin->correo_electronico, 'active' => 0])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertFalse($secondAdmin->fresh()->activo);

        $this->expectExceptionMessageMatches('/única cuenta de administración/');
        try {
            app(SetUserStatus::class)
                ->execute($this->administrator, false, $secondAdmin, request());
        } finally {
            $this->assertTrue($this->administrator->fresh()->activo);
        }
    }

    public function test_archiving_a_teacher_without_syllabi_in_progress_closes_its_assignments(): void
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->assertTrue(TeacherAssignment::query()->forUser($teacher->id)->where('activo', true)->exists());
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $teacher), ['nombre' => $teacher->nombre, 'correo_electronico' => $teacher->correo_electronico, 'active' => 0])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertFalse($teacher->fresh()->activo);
        $this->assertFalse(TeacherAssignment::query()->forUser($teacher->id)->where('activo', true)->exists());
        $this->assertSame($career->id, RoleAssignment::query()->where('usuario_id', $teacher->id)->value('carrera_id'));
    }

    public function test_postgresql_rejects_duplicate_active_role_assignments(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $existing = $teacher->roleAssignments()->firstOrFail();

        $this->expectException(QueryException::class);

        RoleAssignment::query()->create([
            'usuario_id' => $teacher->id,
            'rol' => $existing->rol,
            'carrera_id' => $existing->carrera_id,
            'activo' => true,
        ]);
    }

    public function test_activation_mail_requests_high_priority_headers(): void
    {
        $mail = new ManagedUserActivationMail('Docente', 'Docente', 'https://frontend.test/activar-cuenta?token=abc');
        $message = new SymfonyEmail;

        foreach ($mail->envelope()->using as $customizer) {
            $customizer($message);
        }

        $this->assertSame('high', $message->getHeaders()->getHeaderBody('Importance'));
        $this->assertSame('1', $message->getHeaders()->getHeaderBody('X-Priority'));
        $this->assertSame('urgent', $message->getHeaders()->getHeaderBody('Priority'));
    }

    public function test_activation_mail_names_the_career_and_links_the_header_to_the_spa(): void
    {
        config(['app.url' => 'https://api.test', 'app.frontend_url' => 'https://frontend.test']);
        $mail = new ManagedUserActivationMail('Docente', 'Coordinador', 'https://frontend.test/activar-cuenta?token=abc', 'Software');

        $html = $mail->render();

        $this->assertStringContainsString('como Coordinador de la carrera Software.', strip_tags($html));
        $this->assertStringContainsString('href="https://frontend.test"', $html);
        $this->assertStringNotContainsString('https://api.test', $html);
    }

    public function test_postgresql_rejects_a_scoped_role_for_an_administrator(): void
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();

        $this->expectException(QueryException::class);

        RoleAssignment::query()->create([
            'usuario_id' => $this->administrator->id,
            'rol' => RoleCode::Teacher->value,
            'carrera_id' => $career->id,
            'activo' => true,
        ]);
    }

    public function test_postgresql_rejects_an_administrator_for_a_scoped_user(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->expectException(QueryException::class);

        RoleAssignment::query()->create([
            'usuario_id' => $teacher->id,
            'rol' => RoleCode::Administrator->value,
            'carrera_id' => null,
            'activo' => true,
        ]);
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)
            ->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    /** @return array{nombre: string, correo_electronico: string} */
    private function identityOf(User $user): array
    {
        return ['nombre' => $user->nombre, 'correo_electronico' => $user->correo_electronico];
    }
}
