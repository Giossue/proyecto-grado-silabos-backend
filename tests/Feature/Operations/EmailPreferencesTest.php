<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Application\Actions\StoreInternalNotifications;
use App\Modules\Operations\Infrastructure\Mail\WorkflowNotificationMail;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
        User::query()->update(['correo_verificado_en' => now()]);
    }

    public function test_each_role_sees_only_its_email_notices_all_on_by_default(): void
    {
        $types = fn (string $email): array => collect($this->as($email)->getJson(route('api.v1.notifications.email-preferences.show'))->assertOk()->json('options'))
            ->map(fn (array $option): string => $option['type'].':'.($option['enabled'] ? 'si' : 'no'))->all();

        $this->assertSame([
            'docente.resumen_diario:si', 'convocatoria.abierta:si', 'convocatoria.reanudada:si', 'silabo.correccion_solicitada:si', 'silabo.devuelto_borrador:si', 'silabo.aprobado:si',
        ], $types('docente@silabos.test'));
        $this->assertSame(['coordinacion.resumen_diario:si', 'proceso.reanudado:si'], $types('coordinador@silabos.test'));
        $this->assertSame(['biblioteca.resumen_diario:si', 'coordinacion.aprobado:si', 'silabo.reenviado_biblioteca:si'], $types('bibliotecario@silabos.test'));
        $this->assertSame([], $types('admin@silabos.test'));
    }

    public function test_a_teacher_turns_off_one_notice_and_stops_getting_that_email_only(): void
    {
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->as('docente@silabos.test')->putJson(route('api.v1.notifications.email-preferences.update'), [
            'preferences' => ['silabo.aprobado' => false],
        ])->assertOk()->assertJsonPath('options.5.type', 'silabo.aprobado')->assertJsonPath('options.5.enabled', false);

        $this->notify('silabo.aprobado', $teacher);
        $this->notify('silabo.correccion_solicitada', $teacher);

        Mail::assertQueued(WorkflowNotificationMail::class, 1);
        $this->assertTrue(InternalNotification::query()->where('usuario_id', $teacher->id)->where('tipo', 'silabo.aprobado')->exists());

        $this->as('docente@silabos.test')->putJson(route('api.v1.notifications.email-preferences.update'), [
            'preferences' => ['coordinacion.resumen_diario' => false],
        ])->assertUnprocessable()->assertJsonValidationErrors('preferences');
    }

    public function test_the_library_now_gets_its_notices_by_email(): void
    {
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();

        $this->notify('coordinacion.aprobado', $librarian);
        $this->notify('silabo.reenviado_biblioteca', $librarian);

        Mail::assertQueued(WorkflowNotificationMail::class, 2);
    }

    public function test_the_coordinator_can_turn_off_the_daily_summary(): void
    {
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->as('coordinador@silabos.test')->putJson(route('api.v1.notifications.email-preferences.update'), [
            'preferences' => ['coordinacion.resumen_diario' => false],
        ])->assertOk()->assertJsonPath('options.0.enabled', false);

        $this->assertSame(['coordinacion.resumen_diario' => false], $coordinator->fresh()->preferencias_correo);
    }

    private function notify(string $event, User $user): void
    {
        app(StoreInternalNotifications::class)->execute(
            $event, $event, [$user->id], 'Título del aviso', 'Mensaje del aviso.', 'silabo', (string) Str::uuid(),
        );
    }

    private function as(string $email): static
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();

        return $this->actingAs($user)
            ->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173');
    }
}
