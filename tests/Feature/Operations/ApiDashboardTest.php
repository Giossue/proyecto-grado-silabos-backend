<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
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
            ->assertJsonCount(3, 'metrics')
            ->assertJsonPath('metrics.0.key', 'pending')
            ->assertJsonPath('metrics.1.key', 'correction_requested')
            ->assertJsonPath('metrics.2.key', 'in_review')
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
            ->assertJsonCount(3, 'metrics')
            ->assertJsonPath('deadline', null)
            ->assertJsonPath('coordination_convocation', null)
            ->assertJsonMissingPath('faculty_logo')
            ->assertJsonMissing(['key' => 'faculty_logo'])
            ->assertJsonMissing(['key' => 'sources'])
            ->assertJsonCount(5, 'setup.steps');
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
            ->assertJsonCount(3, 'metrics')
            ->assertJsonPath('deadline.state', 'upcoming')
            ->assertJsonPath('deadline.starts_at', $this->institutionDay(2))
            ->assertJsonPath('deadline.due_at', $this->institutionDay(9))
            ->assertJsonPath('deadline.days_until_start', 2)
            ->assertJsonPath('deadline.days_until_due', 9)
            ->assertJsonPath('deadline.convocation_id', null)
            ->assertJsonPath('coordination_convocation.process_id', $process->id)
            ->assertJsonPath('coordination_convocation.can_open', false)
            ->assertJsonPath('setup.steps.3.key', 'teachers')
            ->assertJsonPath('setup.steps.3.done', false)
            ->assertJsonPath('setup.steps.3.hint', '1 de 2 paralelos tienen un docente vigente.')
            ->assertJsonPath('setup.steps.4.action', null);
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
    }

    public function test_dashboard_requires_an_active_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();

        $this->actingAs($coordinator)
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertConflict();
    }
}
