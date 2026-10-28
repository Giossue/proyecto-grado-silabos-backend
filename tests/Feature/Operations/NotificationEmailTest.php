<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Application\Actions\StoreInternalNotifications;
use App\Modules\Operations\Infrastructure\Mail\WorkflowNotificationMail;
use Database\Seeders\DatabaseSeeder;
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

        foreach (['silabo.enviado', 'silabo.reenviado', 'proceso.plazo_extendido', 'silabo.regenerado', 'documento.exportacion_fallida'] as $event) {
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

        config(['notifications.email.enabled' => true, 'notifications.email.events' => ['silabo.enviado']]);
        $this->notify('silabo.aprobado', key: 'otro');
        $this->notify('silabo.enviado', key: 'ajustado');
        Mail::assertQueued(WorkflowNotificationMail::class, 1);
    }

    public function test_the_email_renders_with_the_notice_and_a_link_to_the_system(): void
    {
        config(['app.frontend_url' => 'https://silabos.ueb.test/']);
        $mail = new WorkflowNotificationMail('Ana', 'Sílabo aprobado: ya puede firmarlo', 'El PDF está listo.', 'https://silabos.ueb.test/');

        $html = $mail->render();
        $this->assertStringContainsString('Sílabo aprobado: ya puede firmarlo', $html);
        $this->assertStringContainsString('El PDF está listo.', $html);
        $this->assertStringContainsString('href="https://silabos.ueb.test/"', $html);
        $this->assertSame('Sílabo aprobado: ya puede firmarlo', $mail->envelope()->subject);
    }
}
