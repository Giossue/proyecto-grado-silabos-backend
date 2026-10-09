<?php

namespace Tests\Feature\Syllabus;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Syllabus\Application\AcademicContextSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParallelSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_syllabus_keeps_the_parallel_code_and_shift_it_was_created_with(): void
    {
        $this->seed(DatabaseSeeder::class);
        $parallel = Parallel::query()->firstOrFail();
        $context = app(AcademicContextSnapshot::class)->build($parallel, AcademicPeriod::query()->firstOrFail());
        $syllabus = (new Syllabus)->forceFill(['contexto_academico' => $context]);
        $syllabus->setRelation('parallel', $parallel);

        $parallel->update(['codigo' => 'Z', 'jornada' => 'nocturna']);

        $this->assertSame(6, $context['schema_version']);
        $this->assertSame('A', $syllabus->academicParallelCode());
        $this->assertSame('matutina', $syllabus->academicParallelShift());
    }

    public function test_an_old_syllabus_without_a_parallel_in_its_context_reads_the_live_parallel(): void
    {
        $this->seed(DatabaseSeeder::class);
        $parallel = Parallel::query()->firstOrFail();
        $syllabus = (new Syllabus)->forceFill(['contexto_academico' => ['schema_version' => 5]]);
        $syllabus->setRelation('parallel', $parallel);

        $this->assertSame($parallel->codigo, $syllabus->academicParallelCode());
        $this->assertSame($parallel->jornada, $syllabus->academicParallelShift());
    }
}
