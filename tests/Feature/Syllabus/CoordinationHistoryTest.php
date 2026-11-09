<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class CoordinationHistoryTest extends TestCase
{
    use RefreshDatabase;
    use UsesInstitutionalDays;

    private User $coordinator;

    private User $teacher;

    private Career $career;

    private Parallel $parallel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        $this->parallel = Parallel::query()->firstOrFail();
    }

    public function test_it_lists_only_the_approved_syllabi_of_past_periods_newest_period_first(): void
    {
        $old = $this->period('2024-2025', -400);
        $recent = $this->period('2025-2026', -1);
        $current = $this->period('2026-2027-B', 0);
        $databases = $this->parallelOf('SW-602', 'Bases de Datos', 'B');

        $oldSyllabus = $this->syllabus($old, $databases);
        [$oldRevision] = $this->revisions($oldSyllabus, $this->teacher, approved: true);
        $recentSyllabus = $this->syllabus($recent, $this->parallel);
        [, $recentRevision] = $this->revisions($recentSyllabus, $this->teacher, approved: true, count: 2);
        $this->revisions($this->syllabus($recent, $databases, 'en_revision'), $this->teacher, approved: false);
        $this->revisions($this->syllabus($current, $this->parallel), $this->teacher, approved: true);

        $response = $this->history()->assertOk()
            ->assertJsonPath('syllabi.total', 2)
            ->assertJsonPath('filters', ['q' => null, 'period' => null])
            ->assertJsonPath('periods', [
                ['id' => $recent->id, 'code' => '2025-2026'],
                ['id' => $old->id, 'code' => '2024-2025'],
            ]);

        $this->assertSame([$recentRevision->id, $oldRevision->id], array_column($response->json('syllabi.data'), 'revision_id'));
        $response->assertJsonPath('syllabi.data.0', [
            'id' => $recentSyllabus->id,
            'revision_id' => $recentRevision->id,
            'period' => '2025-2026',
            'subject' => 'Arquitectura de Software',
            'code' => 'SW-601',
            'parallels' => ['A'],
            'author' => $this->teacher->nombre,
            'approved_at' => $recentRevision->approval()->firstOrFail()->aprobado_en->toIso8601String(),
        ]);
        $response->assertJsonPath('syllabi.data.1.subject', 'Bases de Datos')
            ->assertJsonPath('syllabi.data.1.parallels', ['B']);
    }

    public function test_the_author_is_who_sent_the_approved_revision_even_after_leaving_the_parallel_and_the_career(): void
    {
        $period = $this->period('2025-2026', -30);
        $syllabus = $this->syllabus($period, $this->parallel);
        $this->revisions($syllabus, $this->teacher, approved: true);
        $this->currentConvocation();

        TeacherAssignment::query()->forUser($this->teacher->id)->delete();
        RoleAssignment::query()->where('usuario_id', $this->teacher->id)->update(['activo' => false]);
        $this->assertSame([], $syllabus->teachers()->pluck('usuarios.id')->all());

        $this->history()->assertOk()
            ->assertJsonPath('syllabi.total', 1)
            ->assertJsonPath('syllabi.data.0.id', $syllabus->id)
            ->assertJsonPath('syllabi.data.0.author', $this->teacher->nombre);
    }

    public function test_another_career_is_not_visible(): void
    {
        $period = $this->period('2025-2026', -30);
        $other = Career::query()->create([
            'facultad_id' => $this->career->facultad_id,
            'modalidad' => $this->career->modalidad,
            'codigo_carrera' => 'OTRA',
            'nombre' => 'Otra carrera',
            'activo' => true,
            'cantidad_ciclos_malla' => 8,
        ]);
        $this->revisions($this->syllabus($period, $this->parallelOf('OT-101', 'Materia ajena', 'A', $other), career: $other), $this->teacher, approved: true);

        $this->history()->assertOk()
            ->assertJsonPath('syllabi.total', 0)
            ->assertJsonPath('periods', []);
    }

    public function test_it_searches_by_subject_or_code_and_filters_by_period(): void
    {
        $old = $this->period('2024-2025', -400);
        $recent = $this->period('2025-2026', -1);
        $this->revisions($this->syllabus($old, $this->parallelOf('SW-602', 'Bases de Datos', 'B')), $this->teacher, approved: true);
        $this->revisions($this->syllabus($recent, $this->parallel), $this->teacher, approved: true);
        $this->revisions($this->syllabus($old, $this->parallel), $this->teacher, approved: true);
        $this->currentConvocation();

        $this->history(['q' => 'bases de dátos'])->assertOk()
            ->assertJsonPath('syllabi.total', 1)
            ->assertJsonPath('syllabi.data.0.subject', 'Bases de Datos')
            ->assertJsonPath('filters.q', 'bases de dátos');
        $this->history(['q' => 'sw-601'])->assertOk()->assertJsonPath('syllabi.total', 2);
        $this->history(['period' => $old->id])->assertOk()
            ->assertJsonPath('syllabi.total', 2)
            ->assertJsonPath('filters.period', $old->id)
            ->assertJsonCount(2, 'periods');
        $this->history(['period' => $old->id, 'q' => 'arquitectura'])->assertOk()->assertJsonPath('syllabi.total', 1);
        $this->history(['period' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['period']);
    }

    public function test_only_the_active_coordinator_reads_it(): void
    {
        $this->getJson(route('api.v1.coordination.history.index'))->assertUnauthorized();
        foreach (['docente@silabos.test', 'bibliotecario@silabos.test', 'admin@silabos.test'] as $email) {
            $user = User::query()->where('correo_electronico', $email)->firstOrFail();
            $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
                ->getJson(route('api.v1.coordination.history.index'))->assertForbidden();
        }
    }

    /** @param  array<string, string>  $query */
    private function history(array $query = []): TestResponse
    {
        return $this->actingAs($this->coordinator)
            ->withSession(['active_role_assignment_id' => $this->coordinator->roleAssignments()->firstOrFail()->id])
            ->getJson(route('api.v1.coordination.history.index', $query));
    }

    /** Abre la convocatoria actual: todo lo anterior pasa al historial. */
    private function currentConvocation(): void
    {
        $period = $this->period('2026-2027-ACTUAL', 120);
        SyllabusProcess::query()->create(['periodo_academico_id' => $period->id, 'inicia_en' => $period->fecha_inicio, 'entrega_en' => $period->fecha_inicio->addMonth(), 'estado' => SyllabusProcess::STATE_OPEN]);
    }

    private function period(string $code, int $endsInDays): AcademicPeriod
    {
        return AcademicPeriod::query()->create([
            'codigo' => $code,
            'fecha_inicio' => $this->institutionDay($endsInDays - 150),
            'fecha_fin' => $this->institutionDay($endsInDays),
            'semanas_lectivas' => 16,
        ]);
    }

    private function parallelOf(string $code, string $name, string $parallelCode, ?Career $career = null): Parallel
    {
        $subject = Subject::query()->create([
            'carrera_id' => ($career ?? $this->career)->id,
            'codigo_asignatura' => $code,
            'nombre' => $name,
            'ciclo' => 6,
            'creditos' => 4,
            'horas_ac' => 64,
            'horas_pae' => 32,
            'horas_aa' => 96,
            'activo' => true,
        ]);

        return Parallel::query()->create(['asignatura_id' => $subject->id, 'codigo' => $parallelCode, 'jornada' => 'matutina']);
    }

    private function syllabus(AcademicPeriod $period, Parallel $parallel, string $state = 'aprobado', ?Career $career = null): Syllabus
    {
        $process = SyllabusProcess::query()->firstOrCreate(['periodo_academico_id' => $period->id], [
            // Cada convocatoria empieza con su período: la más reciente es la actual y no va al historial.
            'inicia_en' => $period->fecha_inicio,
            'entrega_en' => $period->fecha_inicio->addMonth(),
            'estado' => SyllabusProcess::STATE_CLOSED,
        ]);
        $convocation = Convocation::query()->firstOrCreate(
            ['carrera_id' => ($career ?? $this->career)->id, 'proceso_id' => $process->id],
            ['estado' => Convocation::STATE_CLOSED],
        );
        $subject = $parallel->subject()->firstOrFail();

        return Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => $parallel->id,
            'estado' => $state,
            'version_bloqueo' => 2,
            'contexto_academico' => [
                'subject' => ['name' => $subject->nombre, 'code' => $subject->codigo_asignatura],
                'parallel' => ['code' => $parallel->codigo, 'shift' => $parallel->jornada],
            ],
            'iniciado_en' => now()->subMonths(6),
            'guardado_en' => now()->subMonths(5),
        ]);
    }

    /** @return list<SyllabusRevision> */
    private function revisions(Syllabus $syllabus, User $author, bool $approved, int $count = 1): array
    {
        $revisions = [];
        foreach (range(1, $count) as $number) {
            $snapshot = ['schema_version' => 1, 'sections' => [], 'number' => $number];
            $revisions[] = SyllabusRevision::query()->create([
                'silabo_id' => $syllabus->id,
                'revision_anterior_id' => $revisions === [] ? null : $revisions[array_key_last($revisions)]->id,
                'numero_revision' => $number,
                'clave_idempotencia' => (string) Str::uuid(),
                'version_bloqueo_origen' => $number,
                'fotografia' => $snapshot,
                'huella_sha256' => hash('sha256', (string) json_encode($snapshot)),
                'enviado_por' => $author->id,
                'enviado_en' => now()->subMonths(5)->addDays($number),
            ]);
        }
        if ($approved) {
            $last = $revisions[array_key_last($revisions)];
            Approval::query()->create([
                'revision_silabo_id' => $last->id,
                'clave_idempotencia' => (string) Str::uuid(),
                'huella_sha256' => hash('sha256', $last->huella_sha256),
                'aprobado_por' => $this->coordinator->id,
                'aprobado_en' => now()->subMonths(4),
            ]);
        }

        return $revisions;
    }
}
