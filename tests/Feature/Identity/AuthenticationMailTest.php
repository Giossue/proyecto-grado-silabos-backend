<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_mail_is_in_spanish_and_link_to_the_spa(): void
    {
        config(['app.url' => 'https://api.test', 'app.frontend_url' => 'https://frontend.test']);
        $user = User::factory()->create();

        $reset = (new ResetPassword('abc'))->toMail($user);
        $html = strip_tags((string) $reset->render());

        $this->assertSame('Solicitud de cambio de contraseña', $reset->subject);
        $this->assertStringNotContainsString('Atentamente,', $html);
        $this->assertStringNotContainsString('Todos los derechos reservados', $html);
        $this->assertStringContainsString('Si el botón no funciona, copie este enlace en su navegador:', $html);
        $this->assertStringNotContainsString('https://api.test', (string) $reset->render());
    }
}
