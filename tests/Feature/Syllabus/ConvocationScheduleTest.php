<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Mail\DailySummaryMail;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class ConvocationScheduleTest extends TestCase
{
    use AssertsInternalNotifications;
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;
    use SignsStatements;
    use UsesInstitutionalDays;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    private User $teacher;

    private RoleAssignment $teacherContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();

        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
    }

    public function test_a_convocation_reads_the_start_and_the_deadline_from_its_process(): void
    {
        $convocation = $this->prepareConvocation();
        $schedule = app(ConvocationSchedule::class);

        $this->assertFalse(Schema::hasTable('fechas_limite_convocatoria'));
        $this->assertTrue($schedule->startsAt($convocation)->equalTo($convocation->process->inicia_en));
        $this->assertTrue($schedule->draftDeadline($convocation)->equalTo($convocation->process->entrega_en));
    }

    public function test_the_deadline_cannot_be_set_before_the_start(): void
    {
        $template = $this->publishedConfiguration();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            'nombre' => 'Proceso invertido',
            'template_id' => $template->id,
            'starts_at' => now()->addMonths(2)->toIso8601String(),
            'due_at' => now()->addMonth()->toIso8601String(),
        ])->assertJsonValidationErrors('due_at');
    }

    public function test_a_teacher_cannot_submit_before_the_convocation_starts(): void
    {
        $syllabus = $this->createValidDraft(startsAt: now()->addWeek());

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('deadline');

        $this->assertDatabaseCount('revisiones_silabo', 0);
    }

    public function test_a_teacher_cannot_submit_once_the_deadline_has_passed(): void
    {
        $syllabus = $this->createValidDraft();
        $this->expireDeadline($syllabus);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('deadline');

        $this->assertDatabaseCount('revisiones_silabo', 0);
        $this->assertSame('borrador', $syllabus->fresh()->estado);
    }

    public function test_a_teacher_can_submit_on_the_delivery_day_even_when_utc_already_moved_on(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-10-22T00:30:00Z'));
        $syllabus = $this->createValidDraft();
        $syllabus->convocation->process()->update([
            'inicia_en' => InstitutionDate::startOfDay('2027-10-20')->toIso8601String(),
            'entrega_en' => InstitutionDate::endOfDay('2027-10-21')->toIso8601String(),
        ]);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        $this->assertDatabaseCount('revisiones_silabo', 1);
    }

    public function test_the_delivery_day_closes_at_the_end_of_the_institutional_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-10-22T05:30:00Z'));
        $syllabus = $this->createValidDraft();
        $syllabus->convocation->process()->update([
            'inicia_en' => InstitutionDate::startOfDay('2027-10-20')->toIso8601String(),
            'entrega_en' => InstitutionDate::endOfDay('2027-10-21')->toIso8601String(),
        ]);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('deadline');

        $this->assertDatabaseCount('revisiones_silabo', 0);
    }

    public function test_once_the_deadline_passes_the_teacher_can_only_read_and_an_extension_reopens_editing(): void
    {
        $syllabus = $this->createValidDraft();
        $field = FieldDefinition::query()->where('quien_llena', 'docente')->firstOrFail();
        $convocation = $this->expireDeadline($syllabus);

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()
            ->assertJsonPath('syllabus.editable', false)
            ->assertJsonPath('syllabus.edit_block_reason', 'El plazo de entrega venció. Pida una prórroga a Administración.');
        $this->actingAsTeacher()->patchJson(
            route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
            $this->validFieldPayload($field, $syllabus->fresh()->version_bloqueo),
        )->assertForbidden();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), [
            'due_at' => now()->addWeek()->toIso8601String(),
        ])->assertSuccessful();

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertJsonPath('syllabus.editable', true);
        $this->actingAsTeacher()->patchJson(
            route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
            $this->validFieldPayload($field, $syllabus->fresh()->version_bloqueo),
        )->assertOk();
    }

    public function test_once_the_deadline_passes_the_teacher_cannot_start_a_new_syllabus(): void
    {
        $this->prepareConvocation();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->expireDeadline($syllabus);

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertForbidden();
        $this->assertSame('sin_iniciar', $syllabus->fresh()->estado);
    }

    public function test_an_extension_cannot_target_a_day_that_already_passed(): void
    {
        $convocation = $this->prepareConvocation();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), [
            'due_at' => $this->institutionDay(-1),
        ])->assertJsonValidationErrors(['due_at' => 'La nueva fecha ya pasó.']);
    }

    public function test_an_extension_reopens_submission_and_keeps_the_previous_date_in_the_audit_trail(): void
    {
        $syllabus = $this->createValidDraft();
        $convocation = $this->expireDeadline($syllabus);
        $previous = $convocation->process->entrega_en;

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), [
            'due_at' => now()->addWeek()->toIso8601String(),
        ])->assertSuccessful()->assertSuccessful();

        $event = AuditEvent::query()
            ->where('accion', 'proceso_silabos.plazo_extendido')
            ->firstOrFail();
        $this->assertSame($previous->toIso8601String(), $event->metadatos['previous_due_at']);
        $this->assertSame(1, $event->metadatos['convocations_reached']);
        $this->assertSame('borrador', $event->metadatos['stage']);
        $this->assertArrayNotHasKey('reason', $event->metadatos);
        $this->assertNotified($this->teacher->id, 'proceso.plazo_extendido', 'se amplió hasta el');
        $this->assertNotNotified($this->coordinator->id, 'proceso.plazo_extendido');
        $this->assertNotNotified($this->administrator->id, 'proceso.plazo_extendido');

        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $this->assertDatabaseCount('revisiones_silabo', 1);
    }

    public function test_an_extension_cannot_move_the_date_backwards(): void
    {
        $convocation = $this->prepareConvocation();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), [
            'due_at' => now()->addDay()->toIso8601String(),
        ])->assertJsonValidationErrors('due_at');
    }

    public function test_only_administration_extends_a_deadline(): void
    {
        $convocation = $this->prepareConvocation();
        $payload = [
            'due_at' => now()->addMonths(2)->toIso8601String(),
        ];

        $this->actingAsTeacher()
            ->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), $payload)
            ->assertForbidden();
        $this->actingAsCoordinator()
            ->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), $payload)
            ->assertForbidden();
    }

    public function test_an_extension_cannot_target_the_start_date(): void
    {
        $convocation = $this->prepareConvocation();
        $process = $convocation->process;
        $previousStart = $process->inicia_en;
        $previousDue = $process->entrega_en;

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), [
            'stage' => 'inicio',
            'due_at' => now()->addMonths(2)->toIso8601String(),
        ])->assertJsonValidationErrors([
            'stage' => 'La prórroga solo modifica la fecha límite de entrega.',
        ]);

        $process->refresh();
        $this->assertTrue($process->inicia_en->equalTo($previousStart));
        $this->assertTrue($process->entrega_en->equalTo($previousDue));
    }

    public function test_opening_the_institutional_process_sends_no_notices(): void
    {
        $this->publishedConfiguration();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => now()->toIso8601String(),
            'due_at' => now()->addMonth()->toIso8601String(),
        ])->assertSuccessful();

        $this->assertNotNotified($this->coordinator->id, 'proceso.abierto');
        $this->assertNotNotified($this->teacher->id, 'proceso.abierto');
    }

    public function test_pausing_and_closing_send_no_notices_but_resuming_tells_teachers_and_coordination(): void
    {
        $syllabus = $this->createValidDraft();
        $process = $syllabus->convocation->process;

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'pausar']))->assertSuccessful();
        foreach ([$this->teacher, $this->coordinator] as $user) {
            $this->assertNotNotified($user->id, 'proceso.pausado');
        }
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'reanudar']))->assertSuccessful();

        $this->assertNotified($this->teacher->id, 'convocatoria.reanudada', 'Ya puede seguir con sus sílabos');
        $this->assertNotified($this->coordinator->id, 'proceso.reanudado', 'Administración reanudó');
        $this->assertNotNotified($this->teacher->id, 'proceso.reanudado');

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'cerrar']))->assertSuccessful();
        foreach ([$this->teacher, $this->coordinator, $this->administrator] as $user) {
            $this->assertNotNotified($user->id, 'proceso.cerrado');
        }
    }

    public function test_resuming_the_career_convocation_tells_its_teachers_each_time(): void
    {
        $syllabus = $this->createValidDraft();
        $convocation = $syllabus->convocation;
        $transition = fn (string $action) => $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, $action]))->assertSuccessful();

        $transition('pausar');
        $this->assertNotNotified($this->teacher->id, 'convocatoria.reanudada');
        $this->actingAsTeacher()->getJson(route('api.v1.dashboard.deadline'))
            ->assertJsonPath('deadline.state', 'paused')->assertJsonPath('deadline.paused_by', 'coordinacion');
        $transition('reanudar');
        $this->assertNotified($this->teacher->id, 'convocatoria.reanudada', 'Ya puede seguir con sus sílabos');
        $this->assertNotNotified($this->coordinator->id, 'proceso.reanudado');

        $this->travel(1)->minutes();
        $transition('pausar');
        $transition('reanudar');
        $this->assertSame(2, InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'convocatoria.reanudada')->count());
    }

    public function test_teachers_get_a_daily_summary_of_what_they_still_owe_unless_they_turn_it_off(): void
    {
        Mail::fake();
        $this->teacher->forceFill(['correo_verificado_en' => now()])->save();
        $this->createValidDraft();

        $this->artisan('avisos:resumen-diario')->assertSuccessful();
        Mail::assertQueued(DailySummaryMail::class, fn (DailySummaryMail $mail): bool => $mail->hasTo($this->teacher->correo_electronico)
            && str_contains($mail->lines[0], '1 sílabo por elaborar o enviar') && str_contains($mail->lines[0], 'Entrega hasta el'));

        Cache::flush();
        Mail::fake();
        $this->teacher->forceFill(['preferencias_correo' => ['docente.resumen_diario' => false]])->save();
        $this->artisan('avisos:resumen-diario')->assertSuccessful();
        Mail::assertNotQueued(DailySummaryMail::class);
    }

    private function expireDeadline(Syllabus $syllabus): Convocation
    {
        $convocation = $syllabus->convocation;
        $convocation->process()->update(['inicia_en' => now()->subDays(2), 'entrega_en' => now()->subDay()]);

        return $convocation->fresh();
    }

    private function createValidDraft(?CarbonInterface $startsAt = null): Syllabus
    {
        $convocation = $this->prepareConvocation($startsAt);
        $syllabus = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();

        $fields = FieldDefinition::query()
            ->where('quien_llena', 'docente')
            ->get();
        foreach ($fields as $field) {
            $this->actingAsTeacher()->patchJson(
                route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]),
                $this->validFieldPayload($field, $syllabus->fresh()->version_bloqueo),
            )->assertOk();
        }

        return $syllabus->fresh();
    }

    private function prepareConvocation(?CarbonInterface $startsAt = null): Convocation
    {
        $template = $this->publishedConfiguration();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, $startsAt ?? now()->subDay(), now()->addMonth())->id,
        ])->assertSuccessful();

        return Convocation::query()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validFieldPayload(FieldDefinition $field, int $lockVersion): array
    {
        return ['version_bloqueo' => $lockVersion, 'value' => $this->answeredValue($field, "Contenido académico {$field->clave}")];
    }

    private function publishedConfiguration(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-15']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();

        return $template->fresh();
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }

    private function actingAsTeacher(): static
    {
        $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacherContext->id]);

        return $this;
    }
}
