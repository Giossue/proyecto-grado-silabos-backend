<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Application\SystemSettings;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-prueba-0000000000004c2e';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config([
            'notifications.email.enabled' => true,
            'notifications.digest.enabled' => true,
            'notifications.digest.time' => '08:00',
            'ai.driver' => 'deepseek',
            'ai.hosted.base_url' => 'https://api.deepseek.com',
            'ai.hosted.model' => 'deepseek-chat',
            'ai.hosted.api_key' => self::KEY,
            'ai.hosted.connect_timeout_seconds' => 2,
            'ai.hosted.timeout_seconds' => 50,
            'catalog.driver' => 'koha',
            'catalog.koha.base_url' => 'https://biblioteca.ueb.edu.ec',
        ]);
        app(SystemSettings::class)->rememberEnvironment();
    }

    public function test_only_administration_sees_the_settings_and_the_key_is_never_returned(): void
    {
        $response = $this->as('admin@silabos.test')->getJson(route('api.v1.admin.system-settings.show'))->assertOk();

        $response->assertJsonPath('ai.driver.value', 'deepseek')
            ->assertJsonPath('ai.driver.source', 'entorno')
            ->assertJsonPath('ai.api_key.configured', true)
            ->assertJsonPath('ai.api_key.last4', '4c2e')
            ->assertJsonPath('notifications.digest_time.value', '08:00')
            ->assertJsonPath('library.base_url.value', 'https://biblioteca.ueb.edu.ec');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        $this->as('coordinador@silabos.test')->getJson(route('api.v1.admin.system-settings.show'))->assertForbidden();
    }

    public function test_notifications_change_at_once_without_password_and_are_audited(): void
    {
        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'notificaciones'), [
            'emails' => false, 'digest' => true, 'digest_time' => '07:30',
        ])->assertOk()
            ->assertJsonPath('notifications.emails.value', false)
            ->assertJsonPath('notifications.emails.source', 'pantalla')
            ->assertJsonPath('notifications.digest_time.value', '07:30');

        $this->assertFalse(config('notifications.email.enabled'));
        $this->assertSame('07:30', config('notifications.digest.time'));
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'ajustes.notificaciones.actualizados']);

        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'notificaciones'), [
            'emails' => true, 'digest' => true, 'digest_time' => '25:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('digest_time');
    }

    public function test_the_ai_key_needs_the_password_is_stored_encrypted_and_can_be_kept_replaced_or_removed(): void
    {
        $ai = ['driver' => 'deepseek', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'connect_timeout' => 2, 'timeout' => 50];

        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'ia'), [...$ai, 'api_key' => 'sk-nueva-11112222'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'ia'), [...$ai, 'api_key' => 'sk-nueva-11112222', 'current_password' => 'incorrecta'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $saved = $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'ia'), [...$ai, 'api_key' => 'sk-nueva-11112222', 'current_password' => 'Demo-2026!'])
            ->assertOk()->assertJsonPath('ai.api_key.last4', '2222')->assertJsonPath('ai.api_key.source', 'pantalla')->assertJsonPath('ai.model.value', 'deepseek-flash');
        $this->assertStringNotContainsString('sk-nueva-11112222', $saved->getContent());
        $stored = (string) DB::table('ajustes_sistema')->where('clave_ajuste', 'ai.api_key')->value('valor_ajuste');
        $this->assertStringNotContainsString('sk-nueva-11112222', $stored);
        $this->assertSame('sk-nueva-11112222', Crypt::decryptString((string) json_decode($stored, true)));
        $this->assertSame('sk-nueva-11112222', config('ai.hosted.api_key'));
        $audit = (string) json_encode(AuditEvent::query()->where('accion', 'ajustes.ia.actualizados')->firstOrFail()->metadatos);
        $this->assertStringContainsString('api_key', $audit);
        $this->assertStringNotContainsString('sk-nueva', $audit);

        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'ia'), [...$ai, 'model' => 'deepseek-chat', 'current_password' => 'Demo-2026!'])
            ->assertOk()->assertJsonPath('ai.api_key.last4', '2222');
        $this->assertSame('sk-nueva-11112222', config('ai.hosted.api_key'));

        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'ia'), [...$ai, 'clear_api_key' => true, 'current_password' => 'Demo-2026!'])
            ->assertOk()->assertJsonPath('ai.api_key.configured', false);
        $this->assertNull(config('ai.hosted.api_key'));
    }

    public function test_the_library_needs_the_password_and_a_url_when_koha_is_on(): void
    {
        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'biblioteca'), ['driver' => 'koha', 'base_url' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'base_url']);

        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'biblioteca'), ['driver' => 'disabled', 'base_url' => '', 'current_password' => 'Demo-2026!'])
            ->assertOk()->assertJsonPath('library.driver.value', 'disabled');
        $this->assertSame('disabled', config('catalog.driver'));
    }

    public function test_queued_work_reads_the_latest_value_without_restarting(): void
    {
        $this->as('admin@silabos.test')->putJson(route('api.v1.admin.system-settings.update', 'notificaciones'), [
            'emails' => false, 'digest' => true, 'digest_time' => '08:00',
        ])->assertOk();
        config(['notifications.email.enabled' => true]);

        event(new JobProcessing('database', $this->createMock(Job::class)));

        $this->assertFalse(config('notifications.email.enabled'));
    }

    public function test_testing_the_connection_uses_the_saved_configuration(): void
    {
        Http::fake([
            'api.deepseek.com/models' => Http::response(['data' => [['id' => 'deepseek-chat']]]),
            'biblioteca.ueb.edu.ec/api/v1/public/libraries*' => Http::response([['library_id' => 'MATRIZ', 'name' => 'Biblioteca Matriz']]),
        ]);

        $this->as('admin@silabos.test')->postJson(route('api.v1.admin.system-settings.test', 'ia'))
            ->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.deepseek.com/models' && $request->hasHeader('Authorization', 'Bearer '.self::KEY));

        $this->as('admin@silabos.test')->postJson(route('api.v1.admin.system-settings.test', 'biblioteca'))
            ->assertOk()->assertJsonPath('ok', true);

        config(['ai.hosted.api_key' => null]);
        $this->as('admin@silabos.test')->postJson(route('api.v1.admin.system-settings.test', 'ia'))
            ->assertOk()->assertJsonPath('ok', false);
    }

    private function as(string $email): static
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();

        return $this->actingAs($user)
            ->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173');
    }
}
