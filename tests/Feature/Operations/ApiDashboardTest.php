<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class ApiDashboardTest extends TestCase
{
    use RefreshDatabase;
    use UsesInstitutionalDays;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173']]);
    }

    public function test_teacher_receives_only_their_dashboard_content(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();

        $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonCount(0, 'metrics')
            ->assertJsonPath('setup', null)
            ->assertJsonPath('deadline', null);
    }

    public function test_coordinator_dashboard_does_not_offer_faculty_logo_management(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();

        $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $coordinator->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonCount(0, 'metrics')
            ->assertJsonPath('deadline', null)
            ->assertJsonPath('coordination_convocation', null)
            ->assertJsonMissingPath('faculty_logo')
            ->assertJsonMissing(['key' => 'faculty_logo'])
            ->assertJsonMissing(['key' => 'sources'])
            ->assertJsonCount(6, 'setup.steps');
    }

    public function test_coordinator_dashboard_uses_the_current_period_and_requires_every_parallel_to_have_a_teacher(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $scheduledSubject = Parallel::query()->firstOrFail();
        $template = app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->firstOrFail()->id,
            'inicia_en' => now()->addDays(2),
            'entrega_en' => now()->addDays(9),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        Parallel::query()->create([
            'asignatura_id' => $scheduledSubject->asignatura_id,
            'codigo' => 'B',
            'jornada' => 'matutina',
        ]);

        $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $coordinator->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk()
            ->assertJsonCount(0, 'metrics')
            ->assertJsonPath('deadline.state', 'upcoming')
            ->assertJsonPath('deadline.starts_at', $this->institutionDay(2))
            ->assertJsonPath('deadline.due_at', $this->institutionDay(9))
            ->assertJsonPath('deadline.days_until_start', 2)
            ->assertJsonPath('deadline.days_until_due', 9)
            ->assertJsonPath('deadline.convocation_id', null)
            ->assertJsonPath('coordination_convocation.process_id', $process->id)
            ->assertJsonPath('coordination_convocation.can_open', false)
            ->assertJsonPath('setup.steps.3.key', 'parallels')
            ->assertJsonPath('setup.steps.3.done', false)
            ->assertJsonPath('setup.steps.3.hint', '1 de 2 paralelos tienen un docente vigente.')
            ->assertJsonPath('setup.steps.5.action', null);
    }

    public function test_library_receives_the_institutional_deadline_to_review_before_it_ends(): void
    {
        $this->seed(DatabaseSeeder::class);
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $request = fn () => $this->actingAs($librarian)
            ->withSession(['active_role_assignment_id' => $librarian->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'));

        SyllabusProcess::query()->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        $request()->assertOk()->assertJsonPath('deadline', null);

        SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->firstOrFail()->id,
            'inicia_en' => now()->subDays(2),
            'entrega_en' => now()->addDays(5),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        $request()->assertOk()
            ->assertJsonPath('deadline.state', 'active')
            ->assertJsonPath('deadline.due_at', $this->institutionDay(5))
            ->assertJsonPath('deadline.days_until_due', 5)
            ->assertJsonPath('deadline.convocation_id', null);
    }

    public function test_every_role_but_administration_gets_its_deadline_alone_to_show_it_on_every_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        SyllabusProcess::query()->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->firstOrFail()->id,
            'inicia_en' => now()->subDay(),
            'entrega_en' => now()->addDays(3),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        $deadline = function (string $email) {
            $user = User::query()->where('correo_electronico', $email)->firstOrFail();

            return $this->actingAs($user)
                ->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
                ->withHeader('Origin', 'http://localhost:5173')
                ->getJson(route('api.v1.dashboard.deadline'))
                ->assertOk();
        };

        $deadline('coordinador@silabos.test')->assertJsonPath('deadline.days_until_due', 3)->assertJsonPath('deadline.convocation_id', null)->assertJsonMissingPath('metrics');
        $deadline('bibliotecario@silabos.test')->assertJsonPath('deadline.state', 'active')->assertJsonPath('deadline.days_until_due', 3);
        $deadline('admin@silabos.test')->assertJsonPath('deadline', null);
        $deadline('coordinador@silabos.test')->assertJsonPath('deadline.paused_by', null);
    }

    public function test_a_pause_says_who_paused_and_administration_sees_its_own_pause(): void
    {
        $this->seed(DatabaseSeeder::class);
        SyllabusProcess::query()->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->firstOrFail()->id,
            'inicia_en' => now()->subDay(),
            'entrega_en' => now()->addDays(3),
            'estado' => SyllabusProcess::STATE_PAUSED,
        ]);
        $deadline = function (string $email) {
            $user = User::query()->where('correo_electronico', $email)->firstOrFail();

            return $this->actingAs($user)
                ->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
                ->withHeader('Origin', 'http://localhost:5173')
                ->getJson(route('api.v1.dashboard.deadline'))
                ->assertOk();
        };

        foreach (['coordinador@silabos.test', 'bibliotecario@silabos.test', 'admin@silabos.test'] as $email) {
            $deadline($email)->assertJsonPath('deadline.state', 'paused')->assertJsonPath('deadline.paused_by', 'administracion');
        }
    }

    public function test_a_teacher_without_syllabi_still_learns_that_the_convocation_is_paused(): void
    {
        $this->seed(DatabaseSeeder::class);
        SyllabusProcess::query()->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        $process = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->firstOrFail()->id,
            'inicia_en' => now()->subDay(),
            'entrega_en' => now()->addDays(3),
            'estado' => SyllabusProcess::STATE_PAUSED,
        ]);
        $teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $deadline = fn () => $this->actingAs($teacher)
            ->withSession(['active_role_assignment_id' => $teacher->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.deadline'))
            ->assertOk();

        $deadline()->assertJsonPath('deadline.state', 'paused')->assertJsonPath('deadline.paused_by', 'administracion')->assertJsonPath('deadline.convocation_id', null);

        $process->update(['estado' => SyllabusProcess::STATE_OPEN]);
        $deadline()->assertJsonPath('deadline', null);
    }

    public function test_without_a_convocation_in_progress_no_career_counts_as_pending_to_convene(): void
    {
        $this->seed(DatabaseSeeder::class);
        $administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $role = $administrator->roleAssignments()->firstOrFail();

        $metrics = $this->actingAs($administrator)->withSession(['active_role_assignment_id' => $role->id])
            ->getJson(route('api.v1.dashboard.show'))->assertOk()->json('metrics');
        $careers = collect($metrics)->firstWhere('key', 'careers_without_convocation');

        $this->assertSame(0, $careers['value']);
        $this->assertSame('Sin proceso en curso', $careers['hint']);
    }

    public function test_a_sole_role_enters_without_choosing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();

        $this->actingAs($coordinator)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk();
    }

    public function test_dashboard_requires_choosing_a_role_when_there_are_several(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        RoleAssignment::query()->create([
            'usuario_id' => $coordinator->id,
            'rol' => RoleCode::Teacher->value,
            'carrera_id' => $coordinator->roleAssignments()->firstOrFail()->carrera_id,
            'activo' => true,
        ]);

        $this->actingAs($coordinator)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertConflict();
    }
}
