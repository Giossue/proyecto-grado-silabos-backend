<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class TeacherSyllabiOrderTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase, UsesInstitutionalDays;

    public function test_teacher_syllabi_are_ordered_by_cycle_and_then_by_subject_name(): void
    {
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        Syllabus::query()->delete();
        AcademicPeriod::query()->update(['fecha_fin' => $this->institutionDay(30)]);
        $template = SyllabusTemplate::query()->create(['nombre' => 'Plantilla orden', 'activo' => true]);
        $convocation = Convocation::query()->create([
            'carrera_id' => Career::query()->valueOrFail('id'),
            'proceso_id' => $this->openSyllabusProcess($template->id)->id,
            'estado' => 'abierta',
        ]);
        $base = Parallel::query()->firstOrFail();
        // Guardados del más reciente al más antiguo en un orden distinto al esperado.
        foreach ([['Zoología', 1, 0], ['Álgebra', 3, 1], ['Biología', 1, 2], ['Cálculo', 2, 3]] as [$name, $cycle, $age]) {
            $parallel = Parallel::query()->create(['asignatura_id' => $base->asignatura_id, 'codigo' => 'O'.$age, 'jornada' => 'matutina']);
            TeacherAssignment::query()->create(['usuario_id' => $teacher->id, 'paralelo_id' => $parallel->id, 'activo' => true]);
            Syllabus::query()->create([
                'convocatoria_id' => $convocation->id,
                'paralelo_id' => $parallel->id,
                'estado' => 'borrador',
                'version_bloqueo' => 1,
                'contexto_academico' => ['subject' => ['name' => $name, 'code' => 'C'.$age, 'cycle' => $cycle]],
                'guardado_en' => now()->subDays($age),
            ]);
        }

        $subjects = $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertOk()
            ->json('syllabi.data.*.subject');

        $this->assertSame(['Biología', 'Zoología', 'Cálculo', 'Álgebra'], $subjects);
    }

    public function test_when_a_new_convocation_opens_the_teacher_only_sees_the_new_one(): void
    {
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        Syllabus::query()->delete();
        // El período en curso aún no termina y ya se abrió la convocatoria del siguiente: solo se ve la nueva.
        $current = AcademicPeriod::query()->firstOrFail();
        $current->update(['fecha_fin' => $this->institutionDay(30)]);
        $template = SyllabusTemplate::query()->create(['nombre' => 'Plantilla períodos', 'activo' => true]);
        $old = $this->openSyllabusProcess($template->id);
        $old->update(['estado' => SyllabusProcess::STATE_CLOSED, 'finalizada_en' => now()]);
        $next = AcademicPeriod::query()->create(['codigo' => 'Período siguiente', 'fecha_inicio' => $this->institutionDay(31), 'fecha_fin' => $this->institutionDay(150), 'semanas_lectivas' => 16]);
        $new = SyllabusProcess::query()->create(['periodo_academico_id' => $next->id, 'inicia_en' => now()->subDay(), 'entrega_en' => now()->addMonth(), 'estado' => SyllabusProcess::STATE_OPEN]);
        $career = Career::query()->valueOrFail('id');
        $base = Parallel::query()->firstOrFail();
        foreach ([[$old, 'Álgebra', 1, 'P1'], [$new, 'Zoología', 2, 'P2']] as [$process, $name, $cycle, $code]) {
            $convocation = Convocation::query()->create(['carrera_id' => $career, 'proceso_id' => $process->id, 'estado' => 'abierta']);
            $parallel = Parallel::query()->create(['asignatura_id' => $base->asignatura_id, 'codigo' => $code, 'jornada' => 'matutina']);
            TeacherAssignment::query()->create(['usuario_id' => $teacher->id, 'paralelo_id' => $parallel->id, 'activo' => true]);
            Syllabus::query()->create(['convocatoria_id' => $convocation->id, 'paralelo_id' => $parallel->id, 'estado' => 'borrador', 'version_bloqueo' => 1,
                'contexto_academico' => ['subject' => ['name' => $name, 'code' => $code, 'cycle' => $cycle]]]);
        }

        $data = $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertOk()
            ->json('syllabi.data');

        $this->assertSame(['Zoología'], array_column($data, 'subject'));
        $this->assertSame(['Período siguiente'], array_column($data, 'period'));
    }

    public function test_teacher_list_says_whether_the_career_has_an_open_convocation(): void
    {
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $list = fn () => $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertOk();
        Convocation::query()->update(['estado' => Convocation::STATE_CLOSED]);
        $list()->assertJsonPath('convocation_open', false);

        $template = SyllabusTemplate::query()->create(['nombre' => 'Plantilla abierta', 'activo' => true]);
        Convocation::query()->create([
            'carrera_id' => Career::query()->valueOrFail('id'),
            'proceso_id' => $this->openSyllabusProcess($template->id)->id,
            'estado' => Convocation::STATE_OPEN,
        ]);
        $list()->assertJsonPath('convocation_open', true);
    }
}
