<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Mail\WorkflowNotificationMail;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class AutomaticProcessCloseTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;
    use UsesInstitutionalDays;

    private const CLASSES_END_IN = 40;

    private const DELIVERY_ENDS_IN = 20;

    private User $administrator;

    private User $coordinator;

    private User $teacher;

    private User $otherTeacher;

    private string $lastClassDay;

    private string $deliveryDay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->lastClassDay = $this->institutionDay(self::CLASSES_END_IN);
        $this->deliveryDay = $this->institutionDay(self::DELIVERY_ENDS_IN);
        AcademicPeriod::query()->firstOrFail()->update([
            'fecha_inicio' => $this->institutionDay(-120),
            'fecha_fin' => $this->lastClassDay,
        ]);
    }

    public function test_the_day_after_the_delivery_day_the_process_is_finalized_like_by_hand_and_audited_as_the_system(): void
    {
        [$process, $convocation] = $this->openedProcess();
        [$draft, $sent] = $this->draftAndSentSyllabi($convocation);
        $notices = InternalNotification::query()->count();
        Mail::fake();

        $this->at($this->dayAfterDelivery(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertLessThan($this->lastClassDay, $this->dayAfterDelivery());
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_CLOSED, $convocation->fresh()?->estado);
        $this->assertSame(Convocation::STATE_OPEN, DB::table('convocatorias_carreras')->where('convocatoria_carrera_id', $convocation->id)->value('estado_previo_cierre_convocatoria_carrera'));
        $this->assertNotNull(DB::table('convocatorias_universidad')->where('convocatoria_universidad_id', $process->id)->value('finalizada_en'));
        $this->assertArrayHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);
        $this->assertArrayNotHasKey('closed_document', $sent->fresh()?->contexto_academico ?? []);
        $audit = AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->where('recurso_id', $process->id)->sole();
        $this->assertNull($audit->actor_usuario_id);
        $this->assertNull($audit->asignacion_rol_id);
        $this->assertSame('exito', $audit->resultado);
        $this->assertEquals(['previous_state' => SyllabusProcess::STATE_OPEN, 'automatic' => true], $audit->metadatos);
        $this->assertSame($notices, InternalNotification::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_a_paused_process_is_also_finalized_once_the_delivery_day_is_over(): void
    {
        [$process, $convocation] = $this->openedProcess();
        $this->transition($process, 'pausar')->assertSuccessful();

        $this->at($this->dayAfterDelivery(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_CLOSED, $convocation->fresh()?->estado);
        $audit = AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->where('recurso_id', $process->id)->sole();
        $this->assertEquals(['previous_state' => SyllabusProcess::STATE_PAUSED, 'automatic' => true], $audit->metadatos);
    }

    public function test_the_process_stays_open_through_its_delivery_day_even_when_utc_already_moved_on(): void
    {
        [$process, $convocation] = $this->openedProcess();

        $this->at($this->deliveryDay, '23:30');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 0.')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_OPEN, $convocation->fresh()?->estado);
        $this->assertFalse(AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->exists());
    }

    public function test_syllabi_under_review_do_not_hold_the_finalization_and_keep_their_state(): void
    {
        [$process, $convocation] = $this->openedProcess();
        [$draft, $sent] = $this->draftAndSentSyllabi($convocation);
        $parallel = $draft->parallel->replicate();
        $parallel->codigo = 'CORREGIR';
        $parallel->save();
        $correction = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => $draft->fresh()?->contexto_academico,
            'estado' => 'correccion_solicitada',
        ]);

        $this->at($this->dayAfterDelivery(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 1.')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertSame('en_revision', $sent->fresh()?->estado);
        $this->assertSame('correccion_solicitada', $correction->fresh()?->estado);
        $this->assertSame('borrador', $draft->fresh()?->estado);
    }

    public function test_an_extension_moves_the_automatic_finalization_to_the_day_after_the_new_delivery_day(): void
    {
        [$process] = $this->openedProcess();
        $newDeliveryDay = $this->institutionDay(self::DELIVERY_ENDS_IN + 5);
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $newDeliveryDay])->assertOk();

        $this->at($this->dayAfterDelivery(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 0.')->assertSuccessful();
        $this->at($newDeliveryDay, '23:59');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 0.')->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);

        $this->at(CarbonImmutable::parse($newDeliveryDay)->addDay()->toDateString(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 1.')->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
    }

    public function test_a_process_already_finalized_by_hand_is_left_as_it_was(): void
    {
        [$process] = $this->openedProcess();
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->at($this->dayAfterDelivery(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $audit = AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->where('recurso_id', $process->id)->sole();
        $this->assertSame($this->administrator->id, $audit->actor_usuario_id);
    }

    public function test_the_automatic_close_runs_once_the_day_after_delivery_and_leaves_the_syllabus_read_only(): void
    {
        [$process, $convocation] = $this->openedProcess();
        [$draft] = $this->draftAndSentSyllabi($convocation);

        $this->at($this->deliveryDay, '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 0.')->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);

        $this->at($this->dayAfterDelivery(), '00:01');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 1.')->assertSuccessful();
        $closedDocument = $draft->fresh()?->contexto_academico['closed_document'] ?? null;
        $this->assertNotNull($closedDocument);

        $this->at($this->dayAfterDelivery(), '09:00');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 0.')->assertSuccessful();
        $this->assertEquals($closedDocument, $draft->fresh()?->contexto_academico['closed_document'] ?? null);
        $this->assertSame(1, AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->count());

        $this->actingAs($this->teacher)
            ->withSession(['active_role_assignment_id' => $this->teacher->roleAssignments()->firstOrFail()->id])
            ->getJson(route('api.v1.teacher.syllabi.show', $draft))
            ->assertOk()
            ->assertJsonPath('syllabus.editable', false)
            ->assertJsonPath('syllabus.edit_block_reason', 'La convocatoria está cerrada: el sílabo queda solo para consulta.');
        $this->transition($process, 'cerrar')->assertForbidden();
    }

    public function test_finalizing_by_hand_still_freezes_unsent_syllabi_and_records_who_did_it(): void
    {
        [$process, $convocation] = $this->openedProcess();
        [$draft, $sent] = $this->draftAndSentSyllabi($convocation);

        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_CLOSED, $convocation->fresh()?->estado);
        $this->assertArrayHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);
        $this->assertArrayNotHasKey('closed_document', $sent->fresh()?->contexto_academico ?? []);
        $audit = AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->where('recurso_id', $process->id)->sole();
        $this->assertSame($this->administrator->id, $audit->actor_usuario_id);
        $this->assertNotNull($audit->asignacion_rol_id);
        $this->assertSame(['previous_state' => SyllabusProcess::STATE_OPEN], $audit->metadatos);
    }

    public function test_three_days_before_the_delivery_day_only_people_with_unapproved_syllabi_are_warned_once(): void
    {
        [$process, $convocation] = $this->openedProcess(withOtherTeacher: true);
        Syllabus::query()->where('convocatoria_id', $convocation->id)
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->otherTeacher->id))
            ->update(['estado' => 'aprobado']);
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $closesOn = CarbonImmutable::parse($this->deliveryDay)->format('d/m/Y');
        Mail::fake();

        $this->at($this->deliveryEndsIn(3), '08:00');
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();

        foreach ([$this->teacher, $this->coordinator] as $person) {
            $notice = InternalNotification::query()->where('usuario_id', $person->id)->where('tipo', 'proceso.cierre_proximo')->sole();
            $this->assertSame('La convocatoria se cierra pronto', $notice->titulo);
            $this->assertSame("El {$closesOn} es el último día de entrega de {$process->nombre}. Lo que no esté aprobado al terminar ese día quedará solo para consulta.", $notice->mensaje);
            $this->assertSame('proceso_silabos', $notice->tipo_recurso);
            $this->assertSame($process->id, $notice->recurso_id);
            $this->assertSame($process->periodo_academico_id, $notice->periodo_academico_id);
            Mail::assertQueued(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo($person->correo_electronico) && $mail->title === 'La convocatoria se cierra pronto');
        }
        Mail::assertQueued(WorkflowNotificationMail::class, 2);
        foreach ([$this->otherTeacher, $this->administrator, $librarian] as $person) {
            $this->assertFalse(InternalNotification::query()->where('usuario_id', $person->id)->where('tipo', 'proceso.cierre_proximo')->exists());
        }
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);

        $this->assertSame('/docente/mis-silabos', $this->openNotice($this->teacher)->json('path'));
        $this->assertSame('/coordinacion/revisiones', $this->openNotice($this->coordinator)->json('path'));
    }

    public function test_the_warning_goes_out_only_three_days_before_delivery_and_also_while_paused(): void
    {
        [$process] = $this->openedProcess();
        $this->transition($process, 'pausar')->assertSuccessful();

        $lastClassWarning = CarbonImmutable::parse($this->lastClassDay)->subDays(3)->toDateString();
        foreach ([$this->deliveryEndsIn(4), $this->deliveryEndsIn(2), $lastClassWarning] as $day) {
            $this->at($day, '08:00');
            $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
            $this->assertSame(0, $this->closingNotices());
        }

        $this->at($this->deliveryEndsIn(3), '08:00');
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
        $this->assertSame(1, InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'proceso.cierre_proximo')->count());
        $this->assertSame(1, InternalNotification::query()->where('usuario_id', $this->coordinator->id)->where('tipo', 'proceso.cierre_proximo')->count());
    }

    public function test_an_extension_brings_a_new_warning_three_days_before_the_new_delivery_day(): void
    {
        [$process] = $this->openedProcess();
        $newDeliveryDay = $this->institutionDay(self::DELIVERY_ENDS_IN + 5);
        $closesOn = CarbonImmutable::parse($newDeliveryDay)->format('d/m/Y');

        $this->at($this->deliveryEndsIn(3), '08:00');
        $this->artisan('convocatorias:avisar-cierre')->expectsOutputToContain('Convocatorias avisadas: 1.')->assertSuccessful();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $newDeliveryDay])->assertOk();

        $this->at(CarbonImmutable::parse($newDeliveryDay)->subDays(3)->toDateString(), '08:00');
        $this->artisan('convocatorias:avisar-cierre')->expectsOutputToContain('Convocatorias avisadas: 1.')->assertSuccessful();
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();

        $notices = InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'proceso.cierre_proximo')->orderBy('notificado_en')->pluck('mensaje');
        $this->assertCount(2, $notices);
        $this->assertStringContainsString("El {$closesOn} es el último día de entrega", (string) $notices->last());
    }

    public function test_the_warning_email_follows_the_preference_and_a_coordinator_who_also_teaches_is_warned_once(): void
    {
        $career = $this->coordinator->roleAssignments()->firstOrFail()->carrera_id;
        RoleAssignment::query()->create(['usuario_id' => $this->coordinator->id, 'carrera_id' => $career, 'rol' => 'docente', 'activo' => true]);
        $base = Parallel::query()->firstOrFail();
        $parallel = Parallel::query()->create(['asignatura_id' => $base->asignatura_id, 'codigo' => 'C', 'jornada' => 'matutina']);
        TeacherAssignment::query()->create(['usuario_id' => $this->coordinator->id, 'paralelo_id' => $parallel->id, 'activo' => true]);
        [$process] = $this->openedProcess();
        $this->actingAs($this->teacher)
            ->withSession(['active_role_assignment_id' => $this->teacher->roleAssignments()->firstOrFail()->id])
            ->putJson(route('api.v1.notifications.email-preferences.update'), ['preferences' => ['proceso.cierre_proximo' => false]])
            ->assertOk();
        Mail::fake();

        $this->at($this->deliveryEndsIn(4), '23:30');
        $this->artisan('convocatorias:avisar-cierre')->expectsOutputToContain('Convocatorias avisadas: 0.')->assertSuccessful();
        $this->assertSame(0, $this->closingNotices());

        $this->at($this->deliveryEndsIn(3), '23:30');
        $this->artisan('convocatorias:avisar-cierre')->expectsOutputToContain('Convocatorias avisadas: 1.')->assertSuccessful();
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();

        foreach ([$this->teacher, $this->coordinator] as $person) {
            $this->assertSame(1, InternalNotification::query()->where('usuario_id', $person->id)->where('tipo', 'proceso.cierre_proximo')->count());
        }
        $this->assertCount(0, Mail::queued(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo($this->teacher->correo_electronico)));
        $this->assertCount(1, Mail::queued(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail): bool => $mail->hasTo($this->coordinator->correo_electronico)));
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);
    }

    public function test_nobody_is_warned_when_every_syllabus_is_approved_or_the_process_is_already_finalized(): void
    {
        [$process, $convocation] = $this->openedProcess();
        Syllabus::query()->where('convocatoria_id', $convocation->id)->update(['estado' => 'aprobado']);

        $this->at($this->deliveryEndsIn(3), '08:00');
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
        $this->assertSame(0, $this->closingNotices());

        Syllabus::query()->where('convocatoria_id', $convocation->id)->update(['estado' => 'borrador']);
        $process->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
        $this->assertSame(0, $this->closingNotices());
    }

    public function test_closing_and_warning_run_every_day_in_the_institution_timezone(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('convocatorias:cerrar-vencidas')->assertSuccessful();
        $this->artisan('schedule:list')->expectsOutputToContain('convocatorias:avisar-cierre')->assertSuccessful();
        $events = collect(app(Schedule::class)->events());
        $close = $events->first(fn ($event): bool => str_contains((string) $event->command, 'convocatorias:cerrar-vencidas'));
        $warn = $events->first(fn ($event): bool => str_contains((string) $event->command, 'convocatorias:avisar-cierre'));

        $this->assertSame('10 0 * * *', $close->expression);
        $this->assertSame('America/Guayaquil', (string) $close->timezone);
        $this->assertTrue($close->withoutOverlapping);
        $this->assertSame('0 8 * * *', $warn->expression);
        $this->assertSame('America/Guayaquil', (string) $warn->timezone);
        $this->assertTrue($warn->withoutOverlapping);
    }

    /** @return array{SyllabusProcess, Convocation} */
    private function openedProcess(bool $withOtherTeacher = false): array
    {
        if ($withOtherTeacher) {
            $this->otherTeacher = User::factory()->create();
            $career = $this->coordinator->roleAssignments()->firstOrFail()->carrera_id;
            RoleAssignment::query()->create(['usuario_id' => $this->otherTeacher->id, 'carrera_id' => $career, 'rol' => 'docente', 'activo' => true]);
            $base = Parallel::query()->firstOrFail();
            $parallel = Parallel::query()->create(['asignatura_id' => $base->asignatura_id, 'codigo' => 'B', 'jornada' => 'matutina']);
            TeacherAssignment::query()->create(['usuario_id' => $this->otherTeacher->id, 'paralelo_id' => $parallel->id, 'activo' => true]);
        }
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => now()->toIso8601String(),
            'due_at' => $this->deliveryDay,
        ])->assertSuccessful();
        $process = SyllabusProcess::query()->firstOrFail();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), ['process_id' => $process->id])->assertSuccessful();

        return [$process->fresh() ?? $process, Convocation::query()->where('proceso_id', $process->id)->firstOrFail()];
    }

    /** @return array{Syllabus, Syllabus} */
    private function draftAndSentSyllabi(Convocation $convocation): array
    {
        $draft = Syllabus::query()->where('convocatoria_id', $convocation->id)->firstOrFail();
        $this->actingAs($this->teacher)
            ->withSession(['active_role_assignment_id' => $this->teacher->roleAssignments()->firstOrFail()->id])
            ->postJson(route('api.v1.teacher.syllabi.start', $draft))->assertSuccessful();
        $parallel = $draft->parallel->replicate();
        $parallel->codigo = 'ENVIADO';
        $parallel->save();
        $sent = Syllabus::query()->create([
            'convocatoria_id' => $convocation->id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => $draft->fresh()?->contexto_academico,
            'estado' => 'en_revision',
        ]);

        return [$draft, $sent];
    }

    private function closingNotices(): int
    {
        return InternalNotification::query()->where('tipo', 'proceso.cierre_proximo')->count();
    }

    private function deliveryEndsIn(int $days): string
    {
        return CarbonImmutable::parse($this->deliveryDay)->subDays($days)->toDateString();
    }

    private function dayAfterDelivery(): string
    {
        return CarbonImmutable::parse($this->deliveryDay)->addDay()->toDateString();
    }

    private function at(string $day, string $time): void
    {
        $this->travelTo(CarbonImmutable::parse("{$day} {$time}:00", 'America/Guayaquil'));
    }

    private function openNotice(User $user): TestResponse
    {
        $notice = InternalNotification::query()->where('usuario_id', $user->id)->where('tipo', 'proceso.cierre_proximo')->sole();

        return $this->actingAs($user)
            ->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id])
            ->postJson(route('api.v1.notifications.open', $notice))->assertOk();
    }

    private function transition(SyllabusProcess $process, string $transition): TestResponse
    {
        return $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, $transition]));
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)->withSession(['active_role_assignment_id' => $this->administrator->roleAssignments()->firstOrFail()->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->coordinator->roleAssignments()->firstOrFail()->id]);

        return $this;
    }
}
