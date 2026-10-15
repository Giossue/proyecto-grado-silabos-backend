<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
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
        // Solo informativo: no lleva a ninguna pantalla.
        $informational = $create('proceso.plazo_extendido', 'proceso_silabos', $admin->id, 'Plazo extendido');
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

    public function test_teacher_notices_keep_the_teaching_context_even_when_coordination_is_active(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['sanctum.stateful' => ['localhost:5173']]);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $coordination = RoleAssignment::query()->where('usuario_id', $coordinator->id)->where('rol', 'coordinador')->firstOrFail();
        RoleAssignment::query()->create(['usuario_id' => $coordinator->id, 'rol' => 'docente', 'carrera_id' => $coordination->carrera_id, 'activo' => true]);
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->valueOrFail('id'),
            'inicia_en' => now()->subDay(), 'entrega_en' => now()->addMonth(), 'estado' => SyllabusProcess::STATE_PAUSED,
        ]);
        $convocation = Convocation::query()->create(['carrera_id' => $coordination->carrera_id, 'proceso_id' => $process->id, 'estado' => Convocation::STATE_PREPARATION]);
        $notice = InternalNotification::query()->create([
            'usuario_id' => $coordinator->id, 'clave_deduplicacion' => "convocatoria.abierta:{$convocation->id}", 'tipo' => 'convocatoria.abierta',
            'titulo' => 'Tiene sílabos por elaborar', 'mensaje' => 'Mensaje de prueba.', 'tipo_recurso' => 'convocatoria', 'recurso_id' => $convocation->id,
            'notificado_en' => now(),
        ]);

        $response = $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $coordination->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk();
        $context = collect($response->json('notifications.data'))->keyBy('id')[$notice->id]['context'];

        $this->assertStringStartsWith('Docencia', $context);
    }
}
