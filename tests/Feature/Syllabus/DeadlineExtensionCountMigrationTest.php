<?php

namespace Tests\Feature\Syllabus;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeadlineExtensionCountMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->migration()->down();
    }

    public function test_each_process_starts_with_the_extensions_already_recorded_in_the_audit(): void
    {
        $extended = $this->insertProcess(AcademicPeriod::query()->valueOrFail('id'));
        $other = $this->insertProcess(AcademicPeriod::query()->create([
            'codigo' => '2027-2028',
            'fecha_inicio' => '2027-05-01',
            'fecha_fin' => '2027-09-30',
            'semanas_lectivas' => 16,
        ])->id);
        $this->recordAudit('proceso_silabos.plazo_extendido', $extended);
        $this->recordAudit('proceso_silabos.plazo_extendido', $extended);
        $this->recordAudit('proceso_silabos.pausado', $other);
        $this->recordAudit('proceso_silabos.plazo_extendido', $other, 'fallido');

        $this->migration()->up();

        $this->assertSame(2, (int) DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $extended)->value('cantidad_prorrogas'));
        $this->assertSame(0, (int) DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $other)->value('cantidad_prorrogas'));
        $this->expectException(QueryException::class);
        DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $other)->update(['cantidad_prorrogas' => -1]);
    }

    public function test_down_removes_the_counter(): void
    {
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('convocatorias_universidad', 'cantidad_prorrogas'));

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('convocatorias_universidad', 'cantidad_prorrogas'));
    }

    private function insertProcess(string $periodId): string
    {
        $id = (string) Str::uuid();
        DB::table('convocatorias_universidad')->insert([
            'convocatoria_universidad_id' => $id,
            'periodo_academico_id' => $periodId,
            'fecha_inicio' => now()->subDay(),
            'fecha_entrega' => now()->addMonth(),
            'estado_convocatoria_universidad' => 'cerrado',
        ]);

        return $id;
    }

    private function recordAudit(string $action, string $processId, string $result = 'exito'): void
    {
        AuditEvent::query()->create([
            'accion' => $action,
            'tipo_recurso' => 'proceso_silabos',
            'recurso_id' => $processId,
            'resultado' => $result,
            'metadatos' => [],
            'ocurrido_en' => now(),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_02_000262_count_deadline_extensions_per_process.php');
    }
}
