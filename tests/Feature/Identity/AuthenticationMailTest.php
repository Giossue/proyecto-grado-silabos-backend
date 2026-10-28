<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_framework_account_mails_are_in_spanish_and_link_to_the_spa(): void
    {
        config(['app.url' => 'https://api.test', 'app.frontend_url' => 'https://frontend.test']);
        $user = User::factory()->create();

        $reset = (new ResetPassword('abc'))->toMail($user);
        $verify = (new VerifyEmail)->toMail($user);
        $html = strip_tags((string) $reset->render());

        $this->assertSame('Restablezca su contraseña', $reset->subject);
        $this->assertSame('Verifique su correo electrónico', $verify->subject);
        $this->assertStringNotContainsString('Atentamente,', $html);
        $this->assertStringNotContainsString('Todos los derechos reservados', $html);
        $this->assertStringContainsString('copie y pegue este enlace', $html);
        $this->assertStringNotContainsString('https://api.test', (string) $reset->render());
    }
}
