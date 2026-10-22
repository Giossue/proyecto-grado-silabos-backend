<?php

namespace Tests\Feature\Syllabus;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConvocationDatesMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_migration_reinterprets_the_old_markers_as_institutional_days(): void
    {
        $process = $this->processWithMarkers('2026-10-21', '2026-10-30');

        $this->migration()->up();

        $process->refresh();
        $this->assertSame(
            InstitutionDate::startOfDay('2026-10-21')->toIso8601String(),
            $process->inicia_en->setTimezone(InstitutionDate::timezone())->toIso8601String(),
        );
        $this->assertSame(
            InstitutionDate::endOfDay('2026-10-30')->toIso8601String(),
            $process->entrega_en->setTimezone(InstitutionDate::timezone())->toIso8601String(),
        );
        $this->assertSame('2026-10-21', InstitutionDate::dayOf($process->inicia_en));
        $this->assertSame('2026-10-30', InstitutionDate::dayOf($process->entrega_en));
    }

    public function test_the_migration_returns_the_markers_to_their_previous_representation(): void
    {
        $process = $this->processWithMarkers('2026-10-21', '2026-10-30');
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        $process->refresh();
        $this->assertSame('2026-10-21 00:00:00', $process->inicia_en->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 23:59:59', $process->entrega_en->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_migration_leaves_the_rows_that_are_not_markers_untouched(): void
    {
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->valueOrFail('id'),
            'inicia_en' => '2026-10-10 09:06:16+00',
            'entrega_en' => '2026-11-11 09:06:16+00',
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);

        $this->migration()->up();

        $process->refresh();
        $this->assertSame('2026-10-10 09:06:16', $process->inicia_en->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-11 09:06:16', $process->entrega_en->utc()->format('Y-m-d H:i:s'));
    }

    private function processWithMarkers(string $start, string $due): SyllabusProcess
    {
        return SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->valueOrFail('id'),
            'inicia_en' => "{$start} 00:00:00+00",
            'entrega_en' => "{$due} 23:59:59+00",
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_21_000226_move_convocation_dates_to_institutional_days.php');
    }
}
