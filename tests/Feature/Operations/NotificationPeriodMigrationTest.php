<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationPeriodMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_notices_take_the_period_of_their_syllabus_convocation_or_process(): void
    {
        $this->seed(DatabaseSeeder::class);
        $migration = $this->migration();
        $migration->down();
        $periodId = AcademicPeriod::query()->valueOrFail('id');
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => $periodId, 'inicia_en' => now()->subDay(), 'entrega_en' => now()->addMonth(),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        $convocation = Convocation::query()->create([
            'carrera_id' => Career::query()->valueOrFail('id'), 'proceso_id' => $process->id, 'estado' => Convocation::STATE_PREPARATION,
        ]);
        $syllabus = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id, 'paralelo_id' => Parallel::query()->valueOrFail('id'),
            'asignatura_id' => Subject::query()->valueOrFail('id'), 'estado' => 'borrador', 'version_bloqueo' => 1,
        ]);
        $userId = User::query()->valueOrFail('id');
        $notices = [];
        foreach (['silabo' => $syllabus->id, 'convocatoria' => $convocation->id, 'proceso_silabos' => $process->id, 'carrera' => $convocation->carrera_id] as $type => $id) {
            $notices[$type] = (string) Str::uuid();
            DB::table('notificaciones')->insert([
                'notificacion_id' => $notices[$type], 'usuario_id' => $userId, 'clave_unica' => "prueba:{$type}",
                'tipo_notificacion' => 'prueba', 'titulo_notificacion' => 'Prueba', 'mensaje_notificacion' => 'Prueba.',
                'tipo_recurso' => $type, 'recurso_id' => $id, 'notificado_en' => now(),
            ]);
        }

        $migration->up();

        $periods = DB::table('notificaciones')->pluck('periodo_academico_id', 'notificacion_id');
        $this->assertSame($periodId, $periods[$notices['silabo']]);
        $this->assertSame($periodId, $periods[$notices['convocatoria']]);
        $this->assertSame($periodId, $periods[$notices['proceso_silabos']]);
        $this->assertNull($periods[$notices['carrera']]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_27_000240_notifications_by_period.php');
    }
}
