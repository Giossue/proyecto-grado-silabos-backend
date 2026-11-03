<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OtherSessionsRememberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->withoutMiddleware(ValidateCsrfToken::class)->withCredentials()->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_closing_other_sessions_ends_the_remember_me_of_other_devices_and_keeps_the_current_one(): void
    {
        $user = User::factory()->create();
        $recaller = Auth::guard('web')->getRecallerName();

        $otherDevice = $this->rememberedLogin($user);
        $this->asFreshDevice()->withCookie($recaller, $otherDevice)
            ->getJson(route('api.v1.session.show'))->assertOk();

        $currentDevice = $this->rememberedLogin($user);
        $response = $this->asFreshDevice()->withCookie($recaller, $currentDevice)
            ->deleteJson(route('api.v1.authentication.sessions.destroy-others'), ['current_password' => 'password'])
            ->assertOk();
        $renewed = $response->getCookie($recaller)?->getValue();
        $this->assertIsString($renewed);
        $this->assertNotSame($currentDevice, $renewed);

        $this->asFreshDevice()->withCookie($recaller, $otherDevice)
            ->getJson(route('api.v1.session.show'))->assertUnauthorized();
        $this->asFreshDevice()->withCookie($recaller, $renewed)
            ->getJson(route('api.v1.session.show'))->assertOk();
    }

    public function test_closing_other_sessions_without_remember_me_keeps_the_current_session_and_sets_no_recaller(): void
    {
        $user = User::factory()->create();
        $before = $user->getRememberToken();

        $response = $this->actingAs($user)
            ->deleteJson(route('api.v1.authentication.sessions.destroy-others'), ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('closed_sessions', 0);

        $this->assertNull($response->getCookie(Auth::guard('web')->getRecallerName()));
        $this->assertNotSame($before, $user->fresh()->getRememberToken());
        $this->getJson(route('api.v1.session.show'))->assertOk();
    }

    private function rememberedLogin(User $user): string
    {
        $value = $this->asFreshDevice()->postJson(route('api.v1.authentication.login'), [
            'email' => $user->correo_electronico,
            'password' => 'password',
            'remember' => true,
        ])->assertOk()->getCookie(Auth::guard('web')->getRecallerName())?->getValue();
        $this->assertIsString($value);

        return $value;
    }

    private function asFreshDevice(): static
    {
        Auth::forgetGuards();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }
}
