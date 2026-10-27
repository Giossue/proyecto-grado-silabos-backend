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
            'periodo_academico_id' => AcademicPeriod::query()->valueOrFail('id'), 'notificado_en' => now(),
        ]);
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
            'periodo_academico_id' => $process->periodo_academico_id, 'notificado_en' => now(),
        ]);

        $response = $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $coordination->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk();
        $context = collect($response->json('notifications.data'))->keyBy('id')[$notice->id]['context'];

        $this->assertStringStartsWith('Docencia', $context);
    }

    public function test_only_notices_of_the_current_period_are_listed_counted_and_marked_read(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['sanctum.stateful' => ['localhost:5173']]);
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $past = AcademicPeriod::query()->create([
            'codigo' => 'TERMINADO', 'fecha_inicio' => '2025-05-01', 'fecha_fin' => '2026-03-31', 'semanas_lectivas' => 16,
        ]);
        $create = fn (string $periodId, string $key): InternalNotification => InternalNotification::query()->create([
            'usuario_id' => $admin->id, 'clave_deduplicacion' => $key, 'tipo' => 'documento.exportacion_fallida',
            'titulo' => 'No se pudieron generar los documentos', 'mensaje' => 'Mensaje de prueba.',
            'periodo_academico_id' => $periodId, 'notificado_en' => now(),
        ]);
        $current = $create(AcademicPeriod::query()->where('codigo', '!=', 'TERMINADO')->valueOrFail('id'), 'actual');
        $old = $create($past->id, 'anterior');
        $request = fn () => $this->actingAs($admin)
            ->withSession(['active_role_assignment_id' => $admin->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173');

        $response = $request()->getJson(route('api.v1.notifications.index'))->assertOk();
        $this->assertSame([$current->id], collect($response->json('notifications.data'))->pluck('id')->all());
        $this->assertSame(1, $response->json('unread_count'));

        $request()->postJson(route('api.v1.notifications.read-all'))->assertOk();
        $this->assertNotNull($current->fresh()?->leido_en);
        $this->assertNull($old->fresh()?->leido_en);
    }
}
