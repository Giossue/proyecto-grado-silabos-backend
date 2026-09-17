<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
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
            ->assertJsonPath('user.can_edit_identity', false)
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

    public function test_login_can_request_a_persistent_remember_cookie(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.authentication.login'), [
                'email' => $teacher->correo_electronico,
                'password' => 'Demo-2026!',
                'remember' => true,
            ])
            ->assertOk();

        $this->assertNotNull($teacher->fresh()->codigo_recordarme);
    }

    public function test_login_throttle_is_scoped_by_email_and_not_only_by_shared_ip(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        foreach (range(1, 6) as $attempt) {
            $this->withHeader('Origin', 'http://localhost:5173')
                ->postJson(route('api.v1.authentication.login'), [
                    'email' => "persona{$attempt}@silabos.test",
                    'password' => 'incorrecta',
                ])
                ->assertUnprocessable();
        }
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

    public function test_temporary_password_is_changed_without_repeating_the_temporary_value(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $teacher->update(['debe_cambiar_contrasena' => true]);

        $this->actingAs($teacher)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->putJson(route('api.v1.authentication.password.update'), [
                'password' => 'Nueva-clave-2026!',
                'password_confirmation' => 'Nueva-clave-2026!',
            ])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false);

        $this->assertFalse($teacher->fresh()->debe_cambiar_contrasena);
    }

    public function test_administrator_can_update_own_profile_through_the_api(): void
    {
        $this->seed(DatabaseSeeder::class);
        $administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();

        $this->actingAs($administrator)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->putJson(route('api.v1.authentication.profile.update'), [
                'nombre' => 'María José Pérez López',
                'correo_electronico' => 'maria.perez@silabos.test',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'MARÍA JOSÉ PÉREZ LÓPEZ')
            ->assertJsonPath('user.email', 'maria.perez@silabos.test')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.can_edit_identity', true);

        $this->assertDatabaseHas('usuarios', [
            'id' => $administrator->id,
            'nombre' => 'MARÍA JOSÉ PÉREZ LÓPEZ',
            'correo_electronico' => 'maria.perez@silabos.test',
            'correo_verificado_en' => null,
        ]);
    }

    public function test_teacher_cannot_update_own_profile_through_the_api(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAs($teacher)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->putJson(route('api.v1.authentication.profile.update'), [
                'nombre' => 'Nombre no autorizado',
                'correo_electronico' => 'otro@silabos.test',
            ])
            ->assertForbidden();
    }

    public function test_temporary_password_blocks_product_api_but_keeps_session_and_change_available(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $teacher->update(['debe_cambiar_contrasena' => true]);

        $this->actingAs($teacher)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.session.show'))
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.notifications.index'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Cambie la contraseña temporal antes de continuar.');
    }

    public function test_unverified_email_can_manage_verification_but_cannot_enter_product_api(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $teacher->markEmailAsUnverified();

        $this->actingAs($teacher)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.session.show'))
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.notifications.index'))
            ->assertForbidden();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.authentication.email-verification.resend'))
            ->assertAccepted();
    }

    public function test_signed_api_link_verifies_an_email_without_an_existing_session(): void
    {
        $user = User::factory()->create();
        $user->markEmailAsUnverified();
        $url = URL::temporarySignedRoute(
            'api.v1.authentication.email-verification.verify',
            now()->addMinute(),
            ['user' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );

        $this->postJson($url)
            ->assertOk()
            ->assertJsonPath('message', 'Correo electrónico verificado. Ya puede ingresar.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_resending_verification_dispatches_only_for_an_unverified_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $user->markEmailAsUnverified();

        $this->actingAs($user)
            ->postJson(route('api.v1.authentication.email-verification.resend'))
            ->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_login_requires_a_second_factor_before_creating_the_authenticated_session(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.authentication.login'), [
                'email' => $user->correo_electronico,
                'password' => 'password',
            ])
            ->assertAccepted()
            ->assertJsonPath('two_factor_required', true)
            ->assertSessionHas('login.id', $user->id);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_read_two_factor_status_through_api(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.authentication.two-factor.status'))
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('confirmation_required', false);
    }

    public function test_authenticated_user_can_begin_and_cancel_two_factor_setup_through_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.authentication.two-factor.enable'))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('confirmation_required', true)
            ->assertJsonStructure(['qr_code_svg']);

        $this->deleteJson(route('api.v1.authentication.two-factor.disable'))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('confirmation_required', false);
    }

    public function test_user_can_list_and_close_an_owned_remote_session(): void
    {
        $user = User::factory()->create();
        DB::table('sesiones')->insert([
            'id' => 'remote-session',
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0',
            'payload' => '',
            'last_activity' => now()->subMinute()->timestamp,
        ]);

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.authentication.sessions.index'))
            ->assertOk()
            ->assertJsonPath('sessions.0.id', 'remote-session')
            ->assertJsonPath('sessions.0.device', 'Chrome en Linux')
            ->assertJsonPath('sessions.0.current', false);

        $this->deleteJson(route('api.v1.authentication.sessions.destroy', ['session' => 'remote-session']))
            ->assertOk();

        $this->assertDatabaseMissing('sesiones', ['id' => 'remote-session']);
    }

    public function test_closing_all_other_sessions_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->deleteJson(route('api.v1.authentication.sessions.destroy-others'), [
                'current_password' => 'incorrecta',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_user_can_regenerate_recovery_codes_with_the_current_password(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $before = $user->recoveryCodes();

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.authentication.two-factor.recovery-codes.regenerate'), [
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes');

        $this->assertNotSame($before, $user->fresh()->recoveryCodes());
    }
}
