<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_fortify_routes_are_not_exposed(): void
    {
        foreach (['forgot-password', 'reset-password', 'two-factor-challenge', 'user/two-factor-authentication'] as $path) {
            $this->postJson('/'.$path, [])->assertNotFound();
        }
        $this->getJson('/user/two-factor-recovery-codes')->assertNotFound();
    }

    public function test_two_factor_setup_and_removal_require_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson(route('api.v1.authentication.two-factor.enable'))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), ['current_password' => 'incorrecta'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_changing_the_password_closes_the_other_sessions_and_rotates_the_remember_token(): void
    {
        $user = User::factory()->create();
        $before = $user->getRememberToken();
        DB::table('sesiones')->insert([
            ['id' => 'otra-sesion', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp],
        ]);

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->putJson(route('api.v1.authentication.password.update'), [
                'current_password' => 'password',
                'password' => 'Nueva-clave-2026!x',
                'password_confirmation' => 'Nueva-clave-2026!x',
            ])->assertOk();

        $this->assertDatabaseMissing('sesiones', ['id' => 'otra-sesion']);
        $this->assertNotSame($before, $user->fresh()->getRememberToken());
    }

    public function test_email_verification_link_is_refused_for_inactive_accounts(): void
    {
        $user = User::factory()->unverified()->create(['activo' => false]);
        $link = URL::temporarySignedRoute('api.v1.authentication.email-verification.verify', now()->addMinutes(10), ['user' => $user->id, 'hash' => sha1($user->getEmailForVerification())], absolute: false);

        $this->postJson($link)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_login_is_limited_per_account_across_addresses(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.'.intdiv($attempt, 250).'.'.($attempt % 250 + 1)])
                ->postJson(route('api.v1.authentication.login'), ['email' => $user->correo_electronico, 'password' => 'incorrecta']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson(route('api.v1.authentication.login'), ['email' => $user->correo_electronico, 'password' => 'password'])
            ->assertStatus(429);
    }
}
