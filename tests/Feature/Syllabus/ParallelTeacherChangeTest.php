<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class ParallelTeacherChangeTest extends TestCase
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

    private User $replacement;

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
        $this->replacement = $this->createTeacher('suplente@silabos.test');
    }

    public function test_the_new_teacher_of_the_parallel_starts_the_syllabus_from_scratch(): void
    {
        $syllabus = $this->validDraft();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();

        $this->removeAssignment($assignment)->assertSuccessful();
        $this->assertDatabaseMissing('asignaciones_paralelo', ['asignacion_paralelo_id' => $assignment->id]);

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertOk()->assertJsonCount(0, 'syllabi.data');
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertForbidden();
        $this->assertSame('borrador', $syllabus->fresh()->estado);

        $this->assignTeacher($this->replacement, $assignment->paralelo_id)->assertSuccessful();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $fresh = Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->sole();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertDatabaseMissing('valores_campo', ['silabo_id' => $syllabus->id]);
        $this->assertSame([$this->replacement->id], $fresh->teachers()->pluck('usuarios.id')->all());

        $this->actingAsReplacement()->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertOk()->assertJsonCount(1, 'syllabi.data')->assertJsonPath('syllabi.data.0.id', $fresh->id);
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $fresh))->assertForbidden();
    }

    public function test_a_new_teacher_never_erases_the_syllabi_of_a_process_whose_classes_ended_and_history_keeps_its_author(): void
    {
        $syllabus = $this->submittedSyllabus();
        $process = $syllabus->convocation->process()->firstOrFail();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'cerrar']))->assertSuccessful();
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()->assertJsonPath('syllabus.editable', false);
        $period = $process->academicPeriod()->firstOrFail();
        $period->update(['fecha_inicio' => $this->institutionDay(-150), 'fecha_fin' => $this->institutionDay(-1)]);

        $this->removeAssignment($assignment)->assertSuccessful();
        $this->assignTeacher($this->replacement, $assignment->paralelo_id)->assertSuccessful();

        $this->assertSame('en_revision', $syllabus->fresh()?->estado);
        $this->assertDatabaseHas('revisiones_silabo', ['silabo_id' => $syllabus->id]);
        $this->assertSame(1, Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->count());

        $this->actingAsTeacher()->getJson(route('api.v1.teacher.history.index'))
            ->assertOk()->assertJsonCount(1, 'careers');
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))->assertOk();
        $this->actingAsReplacement()->getJson(route('api.v1.teacher.history.index'))
            ->assertOk()->assertJsonCount(0, 'careers');
    }

    public function test_a_submitted_syllabus_is_also_discarded_when_the_teacher_changes(): void
    {
        $syllabus = $this->submittedSyllabus();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();

        $this->removeAssignment($assignment)->assertSuccessful();
        $this->assignTeacher($this->replacement, $assignment->paralelo_id)->assertSuccessful();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseMissing('revisiones_silabo', ['silabo_id' => $syllabus->id]);
        $fresh = Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->sole();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->actingAsReplacement()->getJson(route('api.v1.teacher.syllabi.index'))
            ->assertOk()->assertJsonPath('syllabi.data.0.id', $fresh->id);
    }

    public function test_replacing_the_teacher_in_one_step_does_the_same_as_removing_and_assigning_with_the_convocation_open(): void
    {
        $syllabus = $this->submittedSyllabus();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->where('paralelo_id', $syllabus->paralelo_id)->firstOrFail();

        $this->actingAsCoordinator()->putJson(route('api.v1.coordination.academic.parallels.teacher.update', $assignment->paralelo_id), ['user_id' => $this->replacement->id])
            ->assertSuccessful()->assertJsonPath('message', 'Registro académico creado dentro de su carrera.');

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseMissing('asignaciones_paralelo', ['asignacion_paralelo_id' => $assignment->id]);
        $fresh = Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->sole();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertSame([$this->replacement->id], $fresh->teachers()->pluck('usuarios.id')->all());
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.asignacion_docente.eliminacion', 'recurso_id' => $assignment->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.asignacion_docente.creacion']);
    }

    public function test_reopening_with_no_change_keeps_a_really_submitted_syllabus_as_it_was(): void
    {
        $syllabus = $this->submittedSyllabus();
        $process = $syllabus->convocation->process()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'cerrar']))->assertSuccessful();

        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $this->institutionDay(40)])->assertOk();

        $this->assertSame('en_revision', $syllabus->fresh()?->estado);
        $this->assertDatabaseHas('revisiones_silabo', ['silabo_id' => $syllabus->id]);
    }

    public function test_with_the_convocation_open_parallels_can_be_created_edited_and_removed_without_pausing(): void
    {
        $syllabus = $this->validDraft();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();
        $parallel = $assignment->parallel;

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.parallels.store'), [
            'subject_id' => $parallel->asignatura_id, 'codes' => ['Z'], 'shift' => 'matutina', 'user_id' => $this->teacher->id,
        ])->assertSuccessful();
        $created = Parallel::query()->where('asignatura_id', $parallel->asignatura_id)->where('codigo_paralelo', 'Z')->firstOrFail();
        $createdAssignment = $created->teacherAssignments()->where('activo', true)->sole();
        $this->assertSame($this->teacher->id, $createdAssignment->roleAssignment->usuario_id);
        $this->assertSame(0, Syllabus::query()->where('paralelo_id', $created->id)->count());
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.destroy', ['asignacion_docente', $createdAssignment->id]))->assertSuccessful();
        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $created->id))->assertSuccessful();

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $parallel->id), ['code' => $parallel->codigo, 'shift' => 'nocturna'])
            ->assertSuccessful();
        $this->assertSame('nocturna', $syllabus->fresh()->academicParallelShift());
        $this->assertSame('borrador', $syllabus->fresh()->estado);

        // La pantalla marca los paralelos con sílabo enviado para preguntar una sola vez antes de un cambio masivo.
        $flag = fn (): ?bool => collect($this->actingAsCoordinator()->getJson(route('api.v1.coordination.academic.scheduled-subjects.index'))->assertOk()->json('scheduledSubjects'))
            ->flatMap(fn (array $row): array => $row['parallels'])->firstWhere('id', $parallel->id)['sent_syllabus'] ?? null;
        $this->assertFalse($flag());
        $syllabus->forceFill(['estado' => 'en_revision'])->save();
        $this->assertTrue($flag());
    }

    public function test_a_parallel_without_teacher_is_deleted_with_its_untouched_syllabus_but_not_with_a_started_one(): void
    {
        $syllabus = $this->openedSyllabus();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();
        $parallelId = $assignment->paralelo_id;
        $url = route('api.v1.coordination.academic.parallels.destroy', $parallelId);
        $this->removeAssignment($assignment)->assertSuccessful();

        $syllabus->forceFill(['estado' => 'borrador', 'iniciado_en' => now()])->save();
        $this->actingAsCoordinator()->deleteJson($url)->assertJsonValidationErrors('record');
        $this->assertDatabaseHas('silabos', ['silabo_id' => $syllabus->id]);

        $syllabus->forceFill(['estado' => 'sin_iniciar', 'iniciado_en' => null])->save();
        $this->actingAsCoordinator()->deleteJson($url)->assertSuccessful();
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseMissing('paralelos', ['paralelo_id' => $parallelId]);
    }

    public function test_changing_the_shift_of_a_submitted_syllabus_asks_for_confirmation_and_restarts_it(): void
    {
        $syllabus = $this->submittedSyllabus();
        $parallel = $syllabus->parallel;
        $url = route('api.v1.coordination.academic.parallels.update', $parallel->id);

        $this->actingAsCoordinator()->patchJson($url, ['code' => $parallel->codigo, 'shift' => 'nocturna'])
            ->assertUnprocessable()->assertJsonValidationErrors('purge_required');
        $this->assertSame('en_revision', $syllabus->fresh()->estado);
        $this->assertNotSame('nocturna', $parallel->fresh()->jornada);

        $this->actingAsCoordinator()->patchJson($url, ['code' => $parallel->codigo, 'shift' => 'nocturna', 'confirm_purge' => true])
            ->assertSuccessful();
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseMissing('revisiones_silabo', ['silabo_id' => $syllabus->id]);
        $fresh = Syllabus::query()->where('paralelo_id', $parallel->id)->sole();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertSame('nocturna', $fresh->academicParallelShift());
        $this->assertSame([$this->teacher->id], $fresh->teachers()->pluck('usuarios.id')->all());

        $notice = $this->assertNotified($this->teacher->id, 'silabo.regenerado', 'cambió el código o la jornada del paralelo');
        $this->actingAsTeacher()->postJson(route('api.v1.notifications.open', $notice))
            ->assertOk()->assertJsonPath('path', "/docente/mis-silabos/{$fresh->id}");
    }

    public function test_reassigning_the_same_teacher_also_starts_from_scratch(): void
    {
        $syllabus = $this->validDraft();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();

        $this->removeAssignment($assignment)->assertSuccessful();
        $this->assignTeacher($this->teacher, $assignment->paralelo_id)->assertSuccessful();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $syllabus->id]);
        $this->assertSame('sin_iniciar', Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->sole()->estado);
    }

    public function test_a_parallel_still_accepts_a_single_active_teacher(): void
    {
        $this->openedSyllabus();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();

        $this->assignTeacher($this->replacement, $assignment->paralelo_id)->assertJsonValidationErrors('user_id');
    }

    public function test_pausing_sends_no_notices_and_resuming_tells_the_teacher(): void
    {
        $this->openedSyllabus();
        $convocation = Convocation::query()->with('career')->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertSuccessful();
        $this->assertNotNotified($this->teacher->id, 'convocatoria.pausada');
        $this->assertNotified($this->teacher->id, 'convocatoria.reanudada');
        $this->assertNotNotified($this->coordinator->id, 'convocatoria.pausada');
        $this->assertNotNotified($this->coordinator->id, 'convocatoria.reanudada');
    }

    public function test_archiving_a_teacher_with_syllabi_in_progress_is_refused_until_the_parallel_changes_hands(): void
    {
        $this->validDraft();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();

        $this->archiveTeacher()->assertJsonValidationErrors('active');
        $this->assertTrue($this->teacher->fresh()->activo);

        $this->removeAssignment($assignment)->assertSuccessful();
        $this->assignTeacher($this->replacement, $assignment->paralelo_id)->assertSuccessful();

        $this->archiveTeacher()->assertSuccessful();
        $this->assertFalse($this->teacher->fresh()->activo);
    }

    private function archiveTeacher(): TestResponse
    {
        return $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $this->teacher), [
                'nombre' => $this->teacher->nombre,
                'correo_electronico' => $this->teacher->correo_electronico,
                'active' => 0,
            ]);
    }

    private function removeAssignment(TeacherAssignment $assignment): TestResponse
    {
        return $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.destroy', ['asignacion_docente', $assignment->id]));
    }

    private function assignTeacher(User $user, string $parallelId): TestResponse
    {
        return $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
                'user_id' => $user->id,
                'parallel_id' => $parallelId,
            ]);
    }

    private function actingAsReplacement(): static
    {
        $context = $this->replacement->roleAssignments()->firstOrFail();
        $this->actingAs($this->replacement)->withSession(['active_role_assignment_id' => $context->id]);

        return $this;
    }

    private function createTeacher(string $email): User
    {
        $career = Career::query()->where('codigo_carrera', 'SOFTWARE')->firstOrFail();
        $user = User::query()->create([
            'nombre' => 'Docente Suplente',
            'correo_electronico' => $email,
            'correo_verificado_en' => now(),
            'contrasena' => 'Temporal-2026!',
            'activo' => true,
        ]);
        RoleAssignment::query()->create([
            'usuario_id' => $user->id,
            'rol' => RoleCode::Teacher->value,
            'carrera_id' => $career->id,
            'activo' => true,
        ]);

        return $user;
    }

    private function submittedSyllabus(): Syllabus
    {
        $syllabus = $this->validDraft();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        return $syllabus->fresh();
    }

    private function validDraft(): Syllabus
    {
        $syllabus = $this->openedSyllabus();
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

    private function openedSyllabus(): Syllabus
    {
        $template = $this->publishedConfiguration();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $convocation = Convocation::query()->firstOrFail();

        return Syllabus::query()->firstOrFail();
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
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla cambio docente']);
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
