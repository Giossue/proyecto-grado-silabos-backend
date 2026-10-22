<?php

namespace Tests\Feature\Syllabus;

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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvocationStateMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->migration()->down();
    }

    public function test_a_hidden_convocation_without_syllabi_is_erased(): void
    {
        $process = $this->hiddenProcess();
        $convocation = Convocation::query()->create([
            'carrera_id' => Career::query()->valueOrFail('id'),
            'proceso_id' => $process->id,
            'estado' => Convocation::STATE_CLOSED,
        ]);
        $syllabus = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => Parallel::query()->valueOrFail('id'),
            'asignatura_id' => Subject::query()->valueOrFail('id'),
            'estado' => 'borrador',
            'version_bloqueo' => 1,
        ]);

        $this->migration()->up();

        $this->assertDatabaseMissing('convocatorias_universidad', ['convocatoria_universidad_id' => $process->id]);
        $this->assertDatabaseMissing('convocatorias_carreras', ['convocatoria_carrera_id' => $convocation->id]);
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
    }

    public function test_a_hidden_convocation_with_sent_syllabi_becomes_closed(): void
    {
        $process = $this->hiddenProcess();
        $convocation = Convocation::query()->create([
            'carrera_id' => Career::query()->valueOrFail('id'),
            'proceso_id' => $process->id,
            'estado' => Convocation::STATE_CLOSED,
        ]);
        $syllabus = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => Parallel::query()->valueOrFail('id'),
            'asignatura_id' => Subject::query()->valueOrFail('id'),
            'estado' => 'en_revision',
            'version_bloqueo' => 1,
        ]);
        DB::table('revisiones_silabo')->insert([
            'revision_silabo_id' => (string) Str::uuid(),
            'silabo_id' => $syllabus->id,
            'numero_revision' => 1,
            'clave_solicitud' => (string) Str::uuid(),
            'version_borrador' => 1,
            'contenido_enviado' => json_encode(['blocks' => []], JSON_THROW_ON_ERROR),
            'hash_contenido' => str_repeat('a', 64),
            'enviado_por' => User::query()->valueOrFail('id'),
            'enviado_en' => now(),
        ]);

        $this->migration()->up();

        $this->assertDatabaseHas('convocatorias_universidad', [
            'convocatoria_universidad_id' => $process->id,
            'estado_convocatoria_universidad' => SyllabusProcess::STATE_CLOSED,
        ]);
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);
    }

    public function test_the_state_and_the_columns_are_removed(): void
    {
        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('convocatorias_universidad', 'eliminado_en'));
        $this->assertFalse(Schema::hasColumn('convocatorias_universidad', 'eliminado_por'));

        $check = DB::select("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'convocatorias_universidad_estado_check'");
        $this->assertStringNotContainsString('eliminado', $check[0]->def);
    }

    private function hiddenProcess(): SyllabusProcess
    {
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->valueOrFail('id'),
            'inicia_en' => now()->subDay(),
            'entrega_en' => now()->addMonth(),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        DB::table('convocatorias_universidad')
            ->where('convocatoria_universidad_id', $process->id)
            ->update([
                'estado_convocatoria_universidad' => 'eliminado',
                'eliminado_en' => now(),
            ]);

        return $process->fresh();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_22_000227_remove_hidden_convocation_state.php');
    }
}
