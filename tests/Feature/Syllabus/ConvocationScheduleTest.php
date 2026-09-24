<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Carbon\CarbonInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

/**
 * El reglamento de la UEB exige que la planificación microcurricular esté programada
 * antes de iniciar el periodo académico. Estas fechas son la forma de cumplirlo, así que
 * el vencimiento tiene que bloquear de verdad y la prórroga tiene que dejar rastro.
 */
class ConvocationScheduleTest extends TestCase
{
    use AssertsInternalNotifications;
    use ConfiguresFacultyLogos;
    use CreatesSyllabusProcess;
    use RefreshDatabase;

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

        // I-132: la carrera no copia las fechas; las lee de la convocatoria institucional.
        $this->assertFalse(Schema::hasTable('fechas_limite_convocatoria'));
        $this->assertTrue($schedule->startsAt($convocation)->equalTo($convocation->process->inicia_en));
        $this->assertTrue($schedule->draftDeadline($convocation)->equalTo($convocation->process->entrega_en));
    }

    public function test_the_deadline_cannot_be_set_before_the_start(): void
    {
        // Desde I-31 las fechas las fija Administración en el proceso institucional; la
        // convocatoria las hereda y solo puede prorrogarlas hacia adelante.
        [$template] = $this->publishedConfiguration();

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
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('deadline');

        $this->assertDatabaseCount('revisiones_silabo', 0);
        $this->assertSame('borrador', $syllabus->fresh()->estado);
    }

    public function test_an_extension_reopens_submission_and_keeps_the_previous_date_in_the_audit_trail(): void
    {
        $syllabus = $this->createValidDraft();
        $convocation = $this->expireDeadline($syllabus);
        // La fecha vencida es la del proceso: la prórroga es institucional (I-32).
        $previous = $convocation->process->entrega_en;

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), [
            'due_at' => now()->addWeek()->toIso8601String(),
            'reason' => 'Relevo de docente por licencia médica del titular.',
        ])->assertSuccessful()->assertSuccessful();

        $event = AuditEvent::query()
            ->where('accion', 'proceso_silabos.plazo_extendido')
            ->firstOrFail();
        $this->assertSame($previous->toIso8601String(), $event->metadatos['previous_due_at']);
        $this->assertSame(1, $event->metadatos['convocations_reached']);
        $this->assertSame('borrador', $event->metadatos['stage']);
        $this->assertStringContainsString('licencia', $event->metadatos['reason']);
        // Se enteran quienes todavía tienen algo que entregar o revisar.
        $this->assertNotified($this->teacher->id, 'proceso.plazo_extendido', 'se amplió hasta el');
        $this->assertNotified($this->coordinator->id, 'proceso.plazo_extendido', 'se amplió hasta el');
        $this->assertNotNotified($this->administrator->id, 'proceso.plazo_extendido');

        // Con el plazo movido, el envío que antes rebotaba ahora entra.
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
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
            'reason' => 'Quiero adelantar el cierre porque ya casi todos entregaron.',
        ])->assertJsonValidationErrors('due_at');
    }

    public function test_the_extension_requires_a_written_reason(): void
    {
        $convocation = $this->prepareConvocation();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $convocation->proceso_id), [
            'due_at' => now()->addMonths(2)->toIso8601String(),
            'reason' => 'porque sí',
        ])->assertJsonValidationErrors('reason');
    }

    public function test_only_administration_extends_a_deadline(): void
    {
        // El calendario es de la universidad: ni la coordinación ni un docente lo mueven.
        $convocation = $this->prepareConvocation();
        $payload = [
            'due_at' => now()->addMonths(2)->toIso8601String(),
            'reason' => 'Ampliación por relevo docente en dos paralelos.',
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
            'reason' => 'Se intenta retrasar el inicio desde un cliente desactualizado.',
        ])->assertJsonValidationErrors([
            'stage' => 'La prórroga solo modifica la fecha límite de entrega.',
        ]);

        $process->refresh();
        $this->assertTrue($process->inicia_en->equalTo($previousStart));
        $this->assertTrue($process->entrega_en->equalTo($previousDue));
    }

    public function test_opening_the_institutional_process_notifies_every_coordination(): void
    {
        $this->publishedConfiguration();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => now()->toIso8601String(),
            'due_at' => now()->addMonth()->toIso8601String(),
        ])->assertSuccessful();

        $this->assertNotified($this->coordinator->id, 'proceso.abierto', 'Ya puede iniciar la de su carrera; la entrega vence el');
        // Los docentes se enteran cuando su carrera abre su convocatoria, no antes.
        $this->assertNotNotified($this->teacher->id, 'proceso.abierto');
    }

    public function test_pausing_resuming_and_closing_the_process_notify_who_has_pending_work(): void
    {
        $syllabus = $this->createValidDraft();
        $process = $syllabus->convocation->process;

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'pausar']), [
            'reason' => 'Corregir la plantilla institucional.',
        ])->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'reanudar']))->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'cerrar']))->assertSuccessful();

        foreach ([$this->teacher, $this->coordinator] as $user) {
            $this->assertNotified($user->id, 'proceso.pausado', 'está en pausa');
            $this->assertNotified($user->id, 'proceso.reanudado', 'se reanudó');
            $this->assertNotified($user->id, 'proceso.cerrado', 'Ya no se reciben envíos.');
        }
        $this->assertNotNotified($this->administrator->id, 'proceso.cerrado');
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
            ->where('heredado', false)
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
        [$template, $source] = $this->publishedConfiguration();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, $startsAt ?? now()->subDay(), now()->addMonth())->id,
            'source_ids' => [$source->id],
        ])->assertSuccessful();

        return Convocation::query()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validFieldPayload(FieldDefinition $field, int $lockVersion): array
    {
        $optionValues = collect($field->opciones ?? [])->map(function (mixed $option): string {
            if (is_array($option)) {
                return (string) ($option['value'] ?? 'opcion');
            }

            return (string) $option;
        })->filter()->values();

        return match (true) {
            $field->clave === 'unidades' => ['version_bloqueo' => $lockVersion, 'rows' => $this->validPlanningRowsPayload()],
            $field->tipo === 'repetible' => ['version_bloqueo' => $lockVersion, 'rows' => [['data' => ['texto' => "Contenido {$field->clave}"]]]],
            $field->tipo === 'booleano' => ['version_bloqueo' => $lockVersion, 'value' => true],
            $field->tipo === 'numero' => ['version_bloqueo' => $lockVersion, 'value' => 1],
            $field->tipo === 'fecha' => ['version_bloqueo' => $lockVersion, 'value' => now()->toDateString()],
            $field->tipo === 'seleccion_unica' => ['version_bloqueo' => $lockVersion, 'value' => $optionValues->first()],
            $field->tipo === 'seleccion_multiple' => ['version_bloqueo' => $lockVersion, 'value' => [$optionValues->first()]],
            default => ['version_bloqueo' => $lockVersion, 'value' => "Contenido académico {$field->clave}"],
        };
    }

    /** @return array{SyllabusTemplate, AcademicSource} */
    private function publishedConfiguration(): array
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla I-15']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.sources.store'), [
            'nombre' => 'Fuente I-15',
            'description' => 'Documento de apoyo del periodo.',
        ]);
        $source = AcademicSource::query()->firstOrFail();
        $this->actingAsCoordinator()->putJson(route('api.v1.coordination.sources.content.update', $source), [
            'content' => '## Perfil base

Evidencia académica autorizada.',
        ]);

        return [$template->fresh(), $source->fresh()];
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
