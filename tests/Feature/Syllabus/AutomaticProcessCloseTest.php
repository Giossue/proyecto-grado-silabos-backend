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

    private User $administrator;

    private User $coordinator;

    private User $teacher;

    private User $otherTeacher;

    private string $lastClassDay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->lastClassDay = $this->institutionDay(self::CLASSES_END_IN);
        AcademicPeriod::query()->firstOrFail()->update([
            'fecha_inicio' => $this->institutionDay(-120),
            'fecha_fin' => $this->lastClassDay,
        ]);
    }

    public function test_the_day_after_the_last_class_day_the_process_is_finalized_like_by_hand_and_audited_as_the_system(): void
    {
        [$process, $convocation] = $this->openedProcess();
        [$draft, $sent] = $this->draftAndSentSyllabi($convocation);
        $notices = InternalNotification::query()->count();
        Mail::fake();

        $this->at($this->dayAfterClasses(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_CLOSED, $convocation->fresh()?->estado);
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

    public function test_a_paused_process_is_also_finalized_once_classes_are_over(): void
    {
        [$process, $convocation] = $this->openedProcess();
        $this->transition($process, 'pausar')->assertSuccessful();

        $this->at($this->dayAfterClasses(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_CLOSED, $convocation->fresh()?->estado);
        $audit = AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->where('recurso_id', $process->id)->sole();
        $this->assertEquals(['previous_state' => SyllabusProcess::STATE_PAUSED, 'automatic' => true], $audit->metadatos);
    }

    public function test_the_process_stays_open_through_its_last_class_day_even_when_utc_already_moved_on(): void
    {
        [$process, $convocation] = $this->openedProcess();

        $this->at($this->lastClassDay, '23:30');
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);
        $this->assertSame(Convocation::STATE_OPEN, $convocation->fresh()?->estado);
        $this->assertFalse(AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->exists());
    }

    public function test_a_process_already_finalized_by_hand_is_left_as_it_was(): void
    {
        [$process] = $this->openedProcess();
        $this->transition($process, 'cerrar')->assertSuccessful();

        $this->at($this->dayAfterClasses(), '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();
        $this->artisan('convocatorias:cerrar-vencidas')->assertSuccessful();

        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $audit = AuditEvent::query()->where('accion', 'proceso_silabos.cerrado')->where('recurso_id', $process->id)->sole();
        $this->assertSame($this->administrator->id, $audit->actor_usuario_id);
    }

    public function test_the_automatic_close_runs_once_the_day_after_classes_and_leaves_the_syllabus_read_only(): void
    {
        [$process, $convocation] = $this->openedProcess();
        [$draft] = $this->draftAndSentSyllabi($convocation);

        $this->at($this->lastClassDay, '00:10');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 0.')->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);

        $this->at($this->dayAfterClasses(), '00:01');
        $this->artisan('convocatorias:cerrar-vencidas')->expectsOutputToContain('Convocatorias finalizadas: 1.')->assertSuccessful();
        $closedDocument = $draft->fresh()?->contexto_academico['closed_document'] ?? null;
        $this->assertNotNull($closedDocument);

        $this->at($this->dayAfterClasses(), '09:00');
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

    public function test_three_days_before_the_last_class_day_only_people_with_unapproved_syllabi_are_warned_once(): void
    {
        [$process, $convocation] = $this->openedProcess(withOtherTeacher: true);
        Syllabus::query()->where('convocatoria_id', $convocation->id)
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->otherTeacher->id))
            ->update(['estado' => 'aprobado']);
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $closesOn = CarbonImmutable::parse($this->lastClassDay)->format('d/m/Y');
        Mail::fake();

        $this->at($this->classesEndIn(3), '08:00');
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();

        foreach ([$this->teacher, $this->coordinator] as $person) {
            $notice = InternalNotification::query()->where('usuario_id', $person->id)->where('tipo', 'proceso.cierre_proximo')->sole();
            $this->assertSame('La convocatoria se cierra pronto', $notice->titulo);
            $this->assertSame("La convocatoria {$process->nombre} se cierra al terminar el {$closesOn}, último día de clases. Lo que no esté aprobado quedará solo para consulta.", $notice->mensaje);
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

    public function test_the_warning_goes_out_only_three_days_before_and_also_while_paused(): void
    {
        [$process] = $this->openedProcess();
        $this->transition($process, 'pausar')->assertSuccessful();

        foreach ([4, 2] as $daysLeft) {
            $this->at($this->classesEndIn($daysLeft), '08:00');
            $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
            $this->assertSame(0, $this->closingNotices());
        }

        $this->at($this->classesEndIn(3), '08:00');
        $this->artisan('convocatorias:avisar-cierre')->assertSuccessful();
        $this->assertSame(1, InternalNotification::query()->where('usuario_id', $this->teacher->id)->where('tipo', 'proceso.cierre_proximo')->count());
        $this->assertSame(1, InternalNotification::query()->where('usuario_id', $this->coordinator->id)->where('tipo', 'proceso.cierre_proximo')->count());
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

        $this->at($this->classesEndIn(4), '23:30');
        $this->artisan('convocatorias:avisar-cierre')->expectsOutputToContain('Convocatorias avisadas: 0.')->assertSuccessful();
        $this->assertSame(0, $this->closingNotices());

        $this->at($this->classesEndIn(3), '23:30');
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

        $this->at($this->classesEndIn(3), '08:00');
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
            'due_at' => now()->addMonth()->toIso8601String(),
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

    private function classesEndIn(int $days): string
    {
        return CarbonImmutable::parse($this->lastClassDay)->subDays($days)->toDateString();
    }

    private function dayAfterClasses(): string
    {
        return CarbonImmutable::parse($this->lastClassDay)->addDay()->toDateString();
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
