<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** I-163: los avisos solo informativos no llevan enlace; los que piden actuar, sí. */
class NotificationLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_informational_notices_have_no_destination_and_actionable_ones_do(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['sanctum.stateful' => ['localhost:5173']]);
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $create = fn (string $type, string $resourceType, string $resourceId, string $title): InternalNotification => InternalNotification::query()->create([
            'usuario_id' => $admin->id, 'clave_deduplicacion' => "{$type}:{$resourceId}", 'tipo' => $type,
            'titulo' => $title, 'mensaje' => 'Mensaje de prueba.', 'tipo_recurso' => $resourceType, 'recurso_id' => $resourceId,
            'notificado_en' => now(),
        ]);
        // Antes llevaba a la lista de usuarios; es solo informativo.
        $informational = $create('usuario.activado', 'usuario', $admin->id, 'Cuenta activada');
        $actionable = $create('usuario.rol_asignado', 'asignacion_rol', $admin->id, 'Nuevo rol disponible');

        $response = $this->actingAs($admin)
            ->withSession(['active_role_assignment_id' => $admin->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk();
        $byId = collect($response->json('notifications.data'))->keyBy('id');

        $this->assertFalse($byId[$informational->id]['has_destination']);
        $this->assertTrue($byId[$actionable->id]['has_destination']);
    }
}
