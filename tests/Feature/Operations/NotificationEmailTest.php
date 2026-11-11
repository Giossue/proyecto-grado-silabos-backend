<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Application\Actions\StoreInternalNotifications;
use App\Modules\Operations\Infrastructure\Mail\WorkflowNotificationMail;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacher->forceFill(['correo_verificado_en' => now()])->save();
    }

    private function notify(string $event, ?User $user = null, string $key = 'clave'): void
    {
        app(StoreInternalNotifications::class)->execute(
            $event, $key, [($user ?? $this->teacher)->id], 'Título del aviso', 'Mensaje del aviso.', 'silabo', (string) Str::uuid(),
        );
    }

    public function test_only_notices_that_ask_for_an_action_are_emailed(): void
    {
        foreach (['convocatoria.abierta', 'silabo.correccion_solicitada', 'silabo.aprobado'] as $event) {
            $this->notify($event, key: $event);
        }
        Mail::assertQueued(WorkflowNotificationMail::class, 3);
        Mail::assertQueued(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo('docente@silabos.test')
            && $mail->title === 'Título del aviso' && $mail->body === 'Mensaje del aviso.' && $mail->name === $this->teacher->nombre);

        foreach (['biblioteca.aprobado', 'biblioteca.correccion_solicitada', 'proceso.plazo_extendido', 'documento.exportacion_fallida', 'aviso.prueba'] as $event) {
            $this->notify($event, key: $event);
        }
        Mail::assertQueued(WorkflowNotificationMail::class, 3);
        $this->assertDatabaseCount('notificaciones', 8);
    }

    public function test_the_same_notice_never_sends_a_second_email(): void
    {
        $this->notify('silabo.aprobado', key: 'mismo');
        $this->notify('silabo.aprobado', key: 'mismo');

        Mail::assertQueued(WorkflowNotificationMail::class, 1);
        $this->assertDatabaseCount('notificaciones', 1);
    }

    public function test_accounts_without_a_verified_email_or_disabled_get_only_the_bell(): void
    {
        $pending = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $pending->forceFill(['correo_verificado_en' => null])->save();
        $this->notify('silabo.aprobado', $pending, 'pendiente');
        $this->assertDatabaseHas('notificaciones', ['usuario_id' => $pending->id]);

        $this->teacher->forceFill(['activo' => false])->save();
        $this->notify('silabo.aprobado', key: 'inactivo');

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('notificaciones', ['usuario_id' => $this->teacher->id]);
    }

    public function test_emails_can_be_turned_off_or_adjusted_without_touching_the_code(): void
    {
        config(['notifications.email.enabled' => false]);
        $this->notify('silabo.aprobado', key: 'apagado');
        Mail::assertNothingQueued();

        config(['notifications.email.enabled' => true, 'notifications.email.events' => ['silabo.regenerado']]);
        $this->notify('silabo.aprobado', key: 'otro');
        $this->notify('silabo.regenerado', key: 'ajustado');
        Mail::assertQueued(WorkflowNotificationMail::class, 1);
    }

    public function test_the_coordinator_summary_runs_on_weekdays_at_the_institution_hour(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('avisos:resumen-coordinacion')->assertSuccessful();
        $this->artisan('schedule:list')->expectsOutputToContain('avisos:resumen-diario')->assertSuccessful();
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'avisos:resumen-coordinacion'));

        $this->assertSame('0 8 * * 1-5', $event->expression);
        $this->assertSame('America/Guayaquil', (string) $event->timezone);
    }

    public function test_wrong_environment_values_fall_back_to_the_defaults(): void
    {
        $load = function (array $env): array {
            foreach ($env as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
            try {
                return require config_path('notifications.php');
            } finally {
                foreach (array_keys($env) as $key) {
                    unset($_ENV[$key], $_SERVER[$key]);
                }
            }
        };
        $defaults = ['convocatoria.abierta', 'convocatoria.reanudada', 'silabo.correccion_solicitada', 'silabo.devuelto_borrador', 'silabo.aprobado', 'silabo.enviado', 'silabo.reenviado_biblioteca', 'biblioteca.verificado', 'proceso.reanudado', 'silabo.eliminado', 'silabo.regenerado', 'proceso.plantilla_rehecho', 'proceso.eliminado', 'proceso.cierre_proximo'];

        foreach ([['TRUE', ''], ['', '8am'], ['1,true', '25:00'], ['silabo.eliminado, silabo.regenerado', '07:30']] as [$events, $time]) {
            $config = $load(['NOTIFICATION_EMAIL_EVENTS' => $events, 'NOTIFICATION_DIGEST_TIME' => $time]);
            $this->assertSame($events === 'silabo.eliminado, silabo.regenerado' ? ['silabo.eliminado', 'silabo.regenerado'] : $defaults, $config['email']['events']);
            $this->assertSame($time === '07:30' ? '07:30' : '08:00', $config['digest']['time']);
        }
    }

    public function test_the_email_renders_the_notice_under_its_subject_with_a_link_to_the_system(): void
    {
        config(['app.frontend_url' => 'https://silabos.ueb.test/']);
        $mail = new WorkflowNotificationMail('Ana', 'Sílabo aprobado: ya puede firmarlo', 'El PDF está listo.', 'https://silabos.ueb.test/');

        $html = $mail->render();
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('El PDF está listo.', $html);
        $this->assertStringContainsString('href="https://silabos.ueb.test/"', $html);
        $this->assertSame('Sílabo aprobado: ya puede firmarlo', $mail->envelope()->subject);
    }
}
