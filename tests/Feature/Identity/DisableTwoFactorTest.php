<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class DisableTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->secret = app(Google2FA::class)->generateSecretKey();
        $this->user = User::factory()->withTwoFactor()->create(['secreto_dos_factores' => encrypt($this->secret)]);
        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_disabling_two_factor_with_only_the_password_is_refused(): void
    {
        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), ['current_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Ingrese un código de autenticación o de recuperación.');

        $this->assertTrue($this->user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_disabling_two_factor_with_a_wrong_code_is_refused(): void
    {
        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), ['current_password' => 'password', 'code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'El código de autenticación no es válido.');
        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), ['current_password' => 'password', 'recovery_code' => 'no-existe'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'El código de autenticación no es válido.');

        $this->assertTrue($this->user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_disabling_two_factor_with_a_valid_code_but_a_wrong_password_is_refused(): void
    {
        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), [
            'current_password' => 'incorrecta',
            'code' => app(Google2FA::class)->getCurrentOtp($this->secret),
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue($this->user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_password_and_a_valid_authenticator_code_disable_two_factor(): void
    {
        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), [
            'current_password' => 'password',
            'code' => app(Google2FA::class)->getCurrentOtp($this->secret),
        ])->assertOk()->assertJsonPath('enabled', false);

        $this->assertFalse($this->user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_password_and_an_unused_recovery_code_disable_two_factor_and_consume_the_code(): void
    {
        $this->user->forceFill(['codigos_recuperacion_dos_factores' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2']))])->save();

        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), [
            'current_password' => 'password',
            'recovery_code' => 'recovery-code-1',
        ])->assertOk()->assertJsonPath('enabled', false);

        $fresh = $this->user->fresh();
        $this->assertFalse($fresh->hasEnabledTwoFactorAuthentication());
        $this->assertNull($fresh->two_factor_recovery_codes);
    }

    public function test_repeated_wrong_codes_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->deleteJson(route('api.v1.authentication.two-factor.disable'), ['current_password' => 'password', 'code' => '000000'])
                ->assertUnprocessable();
        }

        $this->deleteJson(route('api.v1.authentication.two-factor.disable'), [
            'current_password' => 'password',
            'code' => app(Google2FA::class)->getCurrentOtp($this->secret),
        ])->assertTooManyRequests();
        $this->assertTrue($this->user->fresh()->hasEnabledTwoFactorAuthentication());
    }
}
