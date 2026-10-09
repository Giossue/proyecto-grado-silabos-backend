<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ParallelsPeriodIndependentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_merges_the_same_parallel_of_different_periods_keeping_the_latest_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $migration = require database_path('migrations/2026_10_12_000218_make_parallels_period_independent.php');
        $migration->down();

        $current = Parallel::query()->firstOrFail();
        $oldPeriod = AcademicPeriod::query()->create([
            'codigo' => 'ANTERIOR-MIGRACION',
            'fecha_inicio' => '2020-01-01',
            'fecha_fin' => '2020-06-01',
            'semanas_lectivas' => 16,
        ]);
        DB::table('paralelos')->where('paralelo_id', $current->id)->update(['periodo_academico_id' => AcademicPeriod::query()->where('codigo', '!=', 'ANTERIOR-MIGRACION')->value('id')]);
        $oldId = (string) str()->uuid();
        DB::table('paralelos')->insert([
            'paralelo_id' => $oldId,
            'periodo_academico_id' => $oldPeriod->id,
            'asignatura_id' => $current->asignatura_id,
            'codigo_paralelo' => 'a',
            'jornada_paralelo' => 'nocturna',
        ]);
        $roleId = TeacherAssignment::query()->where('paralelo_id', $current->id)->value('asignacion_rol_id');
        DB::table('asignaciones_paralelo')->insert([
            'asignacion_paralelo_id' => (string) str()->uuid(),
            'paralelo_id' => $oldId,
            'asignacion_rol_id' => $roleId,
            'asignacion_paralelo_activa' => false,
        ]);

        $migration->up();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('paralelos', 'periodo_academico_id'));
        $this->assertSame([$current->id], DB::table('paralelos')->where('asignatura_id', $current->asignatura_id)->pluck('paralelo_id')->all());
        $this->assertSame(1, DB::table('asignaciones_paralelo')->where('paralelo_id', $current->id)->count());
        $this->assertSame(0, DB::table('asignaciones_paralelo')->where('paralelo_id', $oldId)->count());
        $this->assertSame('matutina', DB::table('paralelos')->where('paralelo_id', $current->id)->value('jornada_paralelo'));
    }
}
