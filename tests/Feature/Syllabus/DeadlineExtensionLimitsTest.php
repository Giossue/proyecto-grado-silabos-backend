<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class DeadlineExtensionLimitsTest extends TestCase
{
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use UsesInstitutionalDays;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
    }

    public function test_two_extensions_go_through_and_the_third_is_rejected(): void
    {
        $process = $this->process(dueIn: 10, classesEndIn: 60);

        $this->extend($process, $this->institutionDay(20))->assertOk();
        $this->assertSame(1, $process->fresh()?->cantidad_prorrogas);
        $this->extend($process, $this->institutionDay(30))->assertOk();
        $this->assertSame(2, $process->fresh()?->cantidad_prorrogas);

        $this->extend($process, $this->institutionDay(40))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process' => 'Ya se usaron las 2 prórrogas de esta convocatoria.']);

        $process->refresh();
        $this->assertSame($this->institutionDay(30), InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(2, $process->cantidad_prorrogas);
        $numbers = AuditEvent::query()->where('accion', 'proceso_silabos.plazo_extendido')->where('recurso_id', $process->id)
            ->get()->map(fn (AuditEvent $event): mixed => $event->metadatos['extension_number'] ?? null)->sort()->values()->all();
        $this->assertSame([1, 2], $numbers);
    }

    public function test_the_new_date_cannot_pass_the_last_class_day_but_can_be_that_day(): void
    {
        $process = $this->process(dueIn: 10, classesEndIn: 60);
        $limit = CarbonImmutable::parse($this->institutionDay(60))->format('d/m/Y');

        $this->extend($process, $this->institutionDay(61))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at' => "La nueva fecha no puede pasar del último día de clases ({$limit})."]);
        $process->refresh();
        $this->assertSame($this->institutionDay(10), InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(0, $process->cantidad_prorrogas);

        $this->extend($process, $this->institutionDay(60))->assertOk();
        $process->refresh();
        $this->assertSame($this->institutionDay(60), InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(1, $process->cantidad_prorrogas);
    }

    public function test_a_date_with_time_and_offset_is_read_as_its_institution_day_at_the_limit(): void
    {
        $process = $this->process(dueIn: 10, classesEndIn: 60);
        $lastClassDay = $this->institutionDay(60);

        $this->extend($process, "{$this->institutionDay(61)}T00:30:00-05:00")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at']);
        $process->refresh();
        $this->assertSame($this->institutionDay(10), InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(0, $process->cantidad_prorrogas);

        $this->extend($process, "{$lastClassDay}T23:30:00-05:00")->assertOk();
        $process->refresh();
        $this->assertSame($lastClassDay, InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(1, $process->cantidad_prorrogas);
    }

    public function test_a_paused_process_follows_the_same_extension_limits(): void
    {
        $process = $this->process(dueIn: 10, classesEndIn: 60);
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'pausar']))->assertSuccessful();

        $this->extend($process, $this->institutionDay(61))->assertUnprocessable()->assertJsonValidationErrors(['due_at']);
        $this->extend($process, $this->institutionDay(20))->assertOk();
        $this->extend($process, $this->institutionDay(30))->assertOk();
        $this->extend($process, $this->institutionDay(40))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process' => 'Ya se usaron las 2 prórrogas de esta convocatoria.']);

        $process->refresh();
        $this->assertSame(SyllabusProcess::STATE_PAUSED, $process->estado);
        $this->assertSame($this->institutionDay(30), InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(2, $process->cantidad_prorrogas);
        $this->processList()->assertJsonPath('processes.0.extension_block_reason', 'Ya se usaron las 2 prórrogas de esta convocatoria.');
    }

    public function test_a_rejected_date_does_not_use_up_an_extension(): void
    {
        $process = $this->process(dueIn: 10, classesEndIn: 60);

        foreach ([5, -1, 10] as $day) {
            $this->extend($process, $this->institutionDay($day))->assertUnprocessable()->assertJsonValidationErrors(['due_at']);
        }

        $process->refresh();
        $this->assertSame($this->institutionDay(10), InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(0, $process->cantidad_prorrogas);
    }

    public function test_a_deadline_already_on_the_last_class_day_cannot_be_extended(): void
    {
        $process = $this->process(dueIn: 60, classesEndIn: 60);
        $limit = CarbonImmutable::parse($this->institutionDay(60))->format('d/m/Y');

        $this->extend($process, $this->institutionDay(61))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process' => "El plazo ya llega al último día de clases ({$limit})."]);
        $this->assertSame(0, $process->fresh()?->cantidad_prorrogas);
        $this->processList()->assertJsonPath('processes.0.extension_block_reason', "El plazo ya llega al último día de clases ({$limit}).");
    }

    public function test_the_process_list_says_how_many_extensions_are_left_and_until_when(): void
    {
        $process = $this->process(dueIn: 10, classesEndIn: 60);

        $this->processList()
            ->assertJsonPath('processes.0.extensions_used', 0)
            ->assertJsonPath('processes.0.extensions_max', 2)
            ->assertJsonPath('processes.0.extension_limit_on', $this->institutionDay(60))
            ->assertJsonPath('processes.0.extension_block_reason', null);

        $this->extend($process, $this->institutionDay(20))->assertOk();
        $this->processList()->assertJsonPath('processes.0.extensions_used', 1)
            ->assertJsonPath('processes.0.extension_block_reason', null);

        $this->extend($process, $this->institutionDay(30))->assertOk();
        $this->processList()->assertJsonPath('processes.0.extensions_used', 2)
            ->assertJsonPath('processes.0.extension_block_reason', 'Ya se usaron las 2 prórrogas de esta convocatoria.');
    }

    public function test_once_classes_are_over_there_is_no_day_left_to_extend_to(): void
    {
        $process = $this->process(dueIn: -3, classesEndIn: -1);
        $limit = CarbonImmutable::parse($this->institutionDay(-1))->format('d/m/Y');

        $this->extend($process, $this->institutionDay(5))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process' => "Las clases del período terminaron el {$limit}."]);
        $this->processList()->assertJsonPath('processes.0.extension_block_reason', "Las clases del período terminaron el {$limit}.");
    }

    public function test_a_closed_process_is_reopened_by_an_extension_that_respects_the_same_limits(): void
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla']);
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', SyllabusTemplate::query()->firstOrFail()))->assertSuccessful();
        $process = $this->process(dueIn: 10, classesEndIn: 60);
        $process->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        $this->processList()->assertJsonPath('processes.0.extension_block_reason', null);

        $this->extend($process, $this->institutionDay(61))->assertUnprocessable()->assertJsonValidationErrors(['due_at']);
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);

        $this->extend($process, $this->institutionDay(20))->assertOk()
            ->assertExactJson(['message' => 'Plazo prorrogado. La convocatoria se volvió a abrir.']);
        $process->refresh();
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->estado);
        $this->assertSame($this->institutionDay(20), InstitutionDate::dateOf($process->entrega_en));
        $this->assertSame(1, $process->cantidad_prorrogas);
    }

    public function test_a_confirmation_flag_that_is_not_a_boolean_is_rejected(): void
    {
        $process = $this->process(dueIn: 10, classesEndIn: 60);

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $this->institutionDay(20), 'confirmar_rehacer' => 'quizas'])
            ->assertUnprocessable()->assertJsonValidationErrors(['confirmar_rehacer']);
        $this->assertSame(0, $process->fresh()?->cantidad_prorrogas);
    }

    private function process(int $dueIn, int $classesEndIn): SyllabusProcess
    {
        AcademicPeriod::query()->firstOrFail()->update([
            'fecha_inicio' => $this->institutionDay(-120),
            'fecha_fin' => $this->institutionDay($classesEndIn),
        ]);

        return $this->openSyllabusProcess(
            '',
            InstitutionDate::startOfDay($this->institutionDay(-5)),
            InstitutionDate::endOfDay($this->institutionDay($dueIn)),
        );
    }

    private function extend(SyllabusProcess $process, string $day): TestResponse
    {
        return $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $day]);
    }

    private function processList(): TestResponse
    {
        return $this->actingAsAdministrator()->getJson(route('api.v1.admin.processes.index'))->assertOk();
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)
            ->withSession(['active_role_assignment_id' => $this->administrator->roleAssignments()->firstOrFail()->id]);

        return $this;
    }
}
