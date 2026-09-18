<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_spa_can_request_and_complete_password_recovery_without_disclosing_the_account(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://silabos.example.edu.ec']);
        $user = User::factory()->create();

        $this->postJson(route('api.v1.authentication.password-recovery.send'), [
            'email' => $user->correo_electronico,
        ])->assertAccepted()
            ->assertJsonPath('message', 'Si el correo pertenece a una cuenta activa, recibirá un enlace para restablecer su contraseña.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->assertSame(
                'https://silabos.example.edu.ec/restablecer-contrasena?token='.$notification->token.'&email='.urlencode($user->correo_electronico),
                $notification->toMail($user)->actionUrl,
            );

            $this->postJson(route('api.v1.authentication.password-recovery.reset'), [
                'email' => $user->correo_electronico,
                'token' => $notification->token,
                'password' => 'Josue123456*',
                'password_confirmation' => 'Josue123456*',
            ])->assertOk()
                ->assertJsonPath('message', 'Contraseña restablecida. Ya puede iniciar sesión.');

            return true;
        });

        $user->refresh();
        $this->assertTrue(Hash::check('Josue123456*', $user->contrasena));
    }
}
