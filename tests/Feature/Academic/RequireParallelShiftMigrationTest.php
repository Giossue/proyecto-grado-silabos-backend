<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RequireParallelShiftMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_missing_shifts_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $migration = require database_path('migrations/2026_09_24_000112_exigir_jornada_paralelo.php');
        $migration->down();
        $scheduled = ScheduledSubject::query()->firstOrFail();
        DB::table('paralelos')->insert(['paralelo_id' => (string) str()->uuid(), 'asignatura_ofertada_id' => $scheduled->id, 'codigo_paralelo' => 'S']);
        $night = Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'N', 'jornada' => 'nocturna']);

        $migration->up();
        $migration->up();

        $this->assertSame('matutina', Parallel::query()->where('codigo_paralelo', 'S')->value('jornada_paralelo'));
        $this->assertSame('nocturna', $night->fresh()?->jornada);
        $this->assertSame(0, DB::table('paralelos')->whereNull('jornada_paralelo')->count());
        $this->assertSame('NO', DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'paralelos' AND column_name = 'jornada_paralelo'")->is_nullable);
    }
}
