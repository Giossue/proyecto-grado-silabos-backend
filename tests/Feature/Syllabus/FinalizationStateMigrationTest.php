<?php

namespace Tests\Feature\Syllabus;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinalizationStateMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->migration()->down();
    }

    public function test_finalized_processes_take_their_finalization_and_career_states_from_the_audit(): void
    {
        $closedAt = CarbonImmutable::parse('2026-10-30 15:00:00', 'UTC');
        $closedFromOpen = $this->insertProcess(AcademicPeriod::query()->valueOrFail('id'), 'cerrado', pausedAt: $closedAt->subWeek());
        $closedFromPause = $this->insertProcess($this->period('2025-2026'), 'cerrado', pausedAt: $closedAt->subDay());
        $withoutAudit = $this->insertProcess($this->period('2024-2025'), 'cerrado');
        $open = $this->insertProcess($this->period('2027-2028'), 'abierto', pausedAt: $closedAt->subWeek());
        $this->recordAudit('proceso_silabos.cerrado', 'proceso_silabos', $closedFromOpen, $closedAt->subDays(3), ['previous_state' => 'pausado']);
        $this->recordAudit('proceso_silabos.cerrado', 'proceso_silabos', $closedFromOpen, $closedAt, ['previous_state' => 'abierto']);
        $this->recordAudit('proceso_silabos.cerrado', 'proceso_silabos', $closedFromPause, $closedAt, ['previous_state' => 'pausado']);
        $paused = $this->insertConvocation($closedFromOpen, Career::query()->valueOrFail('id'), 'cerrada');
        $reopened = $this->insertConvocation($closedFromOpen, $this->career('REDES'), 'cerrada');
        $neverPaused = $this->insertConvocation($closedFromOpen, $this->career('CIVIL'), 'cerrada');
        $running = $this->insertConvocation($open, Career::query()->valueOrFail('id'), 'abierta');
        $this->recordAudit('convocatoria.pausada', 'convocatoria', $paused, $closedAt->subDays(2));
        $this->recordAudit('convocatoria.pausada', 'convocatoria', $reopened, $closedAt->subDays(2));
        $this->recordAudit('convocatoria.abierta', 'convocatoria', $reopened, $closedAt->subDay());
        $this->recordAudit('convocatoria.pausada', 'convocatoria', $running, $closedAt->subDay());

        $this->migration()->up();

        $this->assertSame($closedAt->getTimestamp(), $this->processValue($closedFromOpen, 'finalizada_en')?->getTimestamp());
        $this->assertNull($this->processValue($closedFromOpen, 'pausado_en'));
        $this->assertSame($closedAt->getTimestamp(), $this->processValue($closedFromPause, 'finalizada_en')?->getTimestamp());
        $this->assertSame($closedAt->subDay()->getTimestamp(), $this->processValue($closedFromPause, 'pausado_en')?->getTimestamp());
        $this->assertNull($this->processValue($withoutAudit, 'finalizada_en'));
        $this->assertNull($this->processValue($open, 'finalizada_en'));
        $this->assertSame($closedAt->subWeek()->getTimestamp(), $this->processValue($open, 'pausado_en')?->getTimestamp());
        $this->assertSame('pausada', $this->previousState($paused));
        $this->assertSame('abierta', $this->previousState($reopened));
        $this->assertSame('abierta', $this->previousState($neverPaused));
        $this->assertNull($this->previousState($running));
    }

    public function test_the_new_columns_only_admit_values_that_match_the_current_state(): void
    {
        $this->migration()->up();
        $open = $this->insertProcess(AcademicPeriod::query()->valueOrFail('id'), 'abierto');
        $running = $this->insertConvocation($open, Career::query()->valueOrFail('id'), 'abierta');

        foreach ([
            fn () => DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $open)->update(['finalizada_en' => now()]),
            fn () => DB::table('convocatorias_carreras')->where('convocatoria_carrera_id', $running)->update(['estado_previo_cierre_convocatoria_carrera' => 'abierta']),
            fn () => DB::table('convocatorias_carreras')->where('convocatoria_carrera_id', $running)->update(['estado_convocatoria_carrera' => 'cerrada', 'estado_previo_cierre_convocatoria_carrera' => 'preparacion']),
        ] as $invalid) {
            try {
                DB::transaction($invalid);
                $this->fail('La base aceptó un valor que no corresponde al estado.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        DB::table('convocatorias_carreras')->where('convocatoria_carrera_id', $running)->update(['estado_convocatoria_carrera' => 'cerrada', 'estado_previo_cierre_convocatoria_carrera' => 'pausada']);
        $this->assertSame('pausada', $this->previousState($running));
    }

    public function test_down_removes_both_columns(): void
    {
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('convocatorias_universidad', 'finalizada_en'));
        $this->assertTrue(Schema::hasColumn('convocatorias_carreras', 'estado_previo_cierre_convocatoria_carrera'));

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('convocatorias_universidad', 'finalizada_en'));
        $this->assertFalse(Schema::hasColumn('convocatorias_carreras', 'estado_previo_cierre_convocatoria_carrera'));
    }

    private function insertProcess(string $periodId, string $state, ?CarbonImmutable $pausedAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('convocatorias_universidad')->insert([
            'convocatoria_universidad_id' => $id,
            'periodo_academico_id' => $periodId,
            'fecha_inicio' => now()->subMonth(),
            'fecha_entrega' => now()->addMonth(),
            'estado_convocatoria_universidad' => $state,
            'pausado_en' => $pausedAt,
        ]);

        return $id;
    }

    private function insertConvocation(string $processId, string $careerId, string $state): string
    {
        $id = (string) Str::uuid();
        DB::table('convocatorias_carreras')->insert([
            'convocatoria_carrera_id' => $id,
            'carrera_id' => $careerId,
            'convocatoria_universidad_id' => $processId,
            'estado_convocatoria_carrera' => $state,
        ]);

        return $id;
    }

    private function period(string $code): string
    {
        $start = CarbonImmutable::parse(substr($code, 0, 4).'-05-01');

        return AcademicPeriod::query()->create([
            'codigo' => $code,
            'fecha_inicio' => $start->toDateString(),
            'fecha_fin' => $start->addMonths(5)->toDateString(),
            'semanas_lectivas' => 16,
        ])->id;
    }

    private function career(string $code): string
    {
        $base = Career::query()->firstOrFail();

        return Career::query()->create([
            'facultad_id' => $base->facultad_id,
            'codigo_carrera' => $code,
            'nombre' => $code,
            'modalidad' => $base->modalidad,
            'activo' => true,
            'cantidad_ciclos_malla' => 8,
        ])->id;
    }

    /** @param  array<string, mixed>  $metadata */
    private function recordAudit(string $action, string $type, string $resourceId, CarbonImmutable $at, array $metadata = []): void
    {
        AuditEvent::query()->create([
            'accion' => $action,
            'tipo_recurso' => $type,
            'recurso_id' => $resourceId,
            'resultado' => 'exito',
            'metadatos' => $metadata,
            'ocurrido_en' => $at,
        ]);
    }

    private function processValue(string $id, string $column): ?CarbonImmutable
    {
        $value = DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $id)->value($column);

        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }

    private function previousState(string $id): mixed
    {
        return DB::table('convocatorias_carreras')->where('convocatoria_carrera_id', $id)->value('estado_previo_cierre_convocatoria_carrera');
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_03_000265_remember_state_before_finalizing.php');
    }
}
