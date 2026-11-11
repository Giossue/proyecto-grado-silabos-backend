<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\UsesInstitutionalDays;
use Tests\TestCase;

class FinalizedProcessFrozenTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;
    use UsesInstitutionalDays;

    private User $administrator;

    private User $coordinator;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        AcademicPeriod::query()->firstOrFail()->update([
            'fecha_inicio' => $this->institutionDay(-120),
            'fecha_fin' => $this->institutionDay(60),
        ]);
    }

    public function test_a_curriculum_change_warns_and_never_touches_the_syllabi_of_a_finalized_process(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $subject = $draft->parallel->subject;
        $hours = (float) $subject->horas_aa + 16;
        $syllabi = $this->storedSyllabi();
        $data = $this->storedData();

        $this->assertWarned($this->updateSubject($subject, ['horas_aa' => $hours]), $process);
        $this->assertSame($data, $this->storedData());

        $this->updateSubject($subject, ['horas_aa' => $hours, 'confirm_purge' => true])->assertSuccessful();

        $this->assertEquals($hours, $subject->fresh()?->horas_aa);
        $this->assertSame($syllabi, $this->storedSyllabi());
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk()
            ->assertJsonPath('syllabus.editable', false);
    }

    public function test_reopening_catches_a_draft_up_with_the_curriculum_without_asking(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $hours = (float) $subject->horas_aa + 16;
        $this->updateSubject($subject, ['horas_aa' => $hours, 'confirm_purge' => true])->assertSuccessful();

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $reopened = $draft->fresh();
        $this->assertSame('borrador', $reopened?->estado);
        $this->assertArrayNotHasKey('closed_document', $reopened?->contexto_academico ?? []);
        $this->assertEquals($hours, data_get($reopened?->contexto_academico, 'subject.hours_aa'));
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk()
            ->assertJsonPath('syllabus.editable', true);
    }

    public function test_reopening_asks_before_returning_an_approved_syllabus_to_draft_and_a_rejected_reopen_changes_nothing(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $approved = Syllabus::query()->where('estado', 'aprobado')->sole();
        $subject = $draft->parallel->subject;
        $hours = (float) $subject->horas_aa + 16;
        $this->updateSubject($subject, ['horas_aa' => $hours, 'confirm_purge' => true])->assertSuccessful();
        $data = $this->storedData();

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts', ['to_draft' => 1, 'restarted' => 0, 'to_coordination' => 0]);

        $this->assertSame($data, $this->storedData());
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);

        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();

        $fresh = $approved->fresh();
        $this->assertSame('borrador', $fresh?->estado);
        $this->assertDatabaseMissing('revisiones_silabo', ['silabo_id' => $approved->id]);
        $this->assertEquals($hours, data_get($fresh?->contexto_academico, 'subject.hours_aa'));
        $this->assertTrue(InternalNotification::query()->where('tipo', 'silabo.devuelto_borrador')->where('recurso_id', $approved->id)->exists());
        $this->assertEquals($hours, data_get($draft->fresh()?->contexto_academico, 'subject.hours_aa'));
    }

    public function test_reopening_with_nothing_pending_keeps_an_approved_syllabus_and_does_not_ask(): void
    {
        [$process] = $this->finalizedProcess(withApproved: true);
        $approved = Syllabus::query()->where('estado', 'aprobado')->sole();

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $this->assertSame('aprobado', $approved->fresh()?->estado);
        $this->assertDatabaseHas('revisiones_silabo', ['silabo_id' => $approved->id]);
    }

    public function test_once_classes_end_a_change_neither_warns_nor_touches_the_finalized_process(): void
    {
        [, $draft] = $this->finalizedProcess(withApproved: true);
        $subject = $draft->parallel->subject;
        $stored = $this->storedSyllabi();

        $this->travelTo(InstitutionDate::startOfDay($this->institutionDay(61))->addHour());
        $this->updateSubject($subject, ['horas_aa' => (float) $subject->horas_aa + 16])->assertSuccessful();
        $this->saveProfile($subject, 'Ficha cambiada después de clases')->assertOk();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.parallels.store'), [
            'subject_id' => $subject->id, 'codes' => ['Z'], 'shift' => 'matutina', 'user_id' => $this->teacher->id,
        ])->assertSuccessful();

        $this->assertSame($stored, $this->storedSyllabi());
    }

    public function test_with_a_newer_process_a_change_neither_warns_nor_touches_the_finalized_one(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $subject = $draft->parallel->subject;
        $stored = $this->storedSyllabi();
        $newer = SyllabusProcess::query()->create([
            'periodo_academico_id' => AcademicPeriod::query()->create([
                'codigo' => '2027-2028',
                'fecha_inicio' => $this->institutionDay(61),
                'fecha_fin' => $this->institutionDay(200),
                'semanas_lectivas' => 16,
            ])->id,
            'inicia_en' => InstitutionDate::startOfDay($this->institutionDay()),
            'entrega_en' => InstitutionDate::endOfDay($this->institutionDay(90)),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);

        $this->updateSubject($subject, ['horas_aa' => (float) $subject->horas_aa + 16])->assertSuccessful();
        $this->assertSame($stored, $this->storedSyllabi());

        $newer->update(['estado' => SyllabusProcess::STATE_CLOSED]);
        $this->updateSubject($subject, ['horas_aa' => (float) $subject->horas_aa + 32])->assertSuccessful();
        $this->assertSame($stored, $this->storedSyllabi());
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
    }

    public function test_without_prorogations_left_a_change_does_not_warn_because_it_cannot_reopen(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $process->update(['cantidad_prorrogas' => SyllabusProcess::MAX_EXTENSIONS]);
        $stored = $this->storedSyllabi();
        $subject = $draft->parallel->subject;

        $this->updateSubject($subject, ['horas_aa' => (float) $subject->horas_aa + 16])->assertSuccessful();

        $this->assertSame($stored, $this->storedSyllabi());
    }

    public function test_a_change_that_would_not_reach_the_syllabi_does_not_warn(): void
    {
        [, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $sibling = Subject::query()->where('carrera_id', $subject->carrera_id)->where('ciclo', $subject->ciclo)->whereKeyNot($subject->id)->first()
            ?? Subject::query()->create([
                'carrera_id' => $subject->carrera_id, 'codigo_asignatura' => 'SIN-PARALELO', 'nombre' => 'Materia sin paralelos',
                'ciclo' => $subject->ciclo, 'orden_en_ciclo' => 90, 'activo' => true, 'genera_silabo' => true,
            ]);
        $stored = $this->storedSyllabi();

        $this->actingAsCoordinator()->patchJson(route('api.v1.coordination.academic.curriculum.layout.update', $subject->carrera_id), [
            'subject_id' => $sibling->id, 'cycle' => $sibling->ciclo, 'position' => 0,
        ])->assertSuccessful();

        $this->assertSame($stored, $this->storedSyllabi());
    }

    public function test_every_role_keeps_seeing_the_finalized_banner_until_a_new_convocation_exists(): void
    {
        [$process] = $this->finalizedProcess();
        $deadline = fn (): TestResponse => $this->getJson(route('api.v1.dashboard.deadline'))->assertOk();
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();

        $this->actingAsAdministrator();
        $deadline()->assertJsonPath('deadline.state', 'closed')->assertJsonPath('deadline.classes_ended', false);
        $this->actingAsCoordinator();
        $deadline()->assertJsonPath('deadline.state', 'closed');
        $this->actingAsTeacher();
        $deadline()->assertJsonPath('deadline.state', 'closed');
        $this->actingAs($librarian)->withSession(['active_role_assignment_id' => $librarian->roleAssignments()->firstOrFail()->id]);
        $deadline()->assertJsonPath('deadline.state', 'closed');

        $this->travelTo(CarbonImmutable::parse($this->institutionDay(61), 'America/Guayaquil')->setTime(12, 0));
        $this->actingAsAdministrator();
        $deadline()->assertJsonPath('deadline.state', 'closed')->assertJsonPath('deadline.classes_ended', true);
        $this->actingAsTeacher();
        $deadline()->assertJsonPath('deadline.state', 'closed');
        $this->travelBack();

        $period = AcademicPeriod::query()->create([
            'codigo' => 'PERIODO-SIGUIENTE',
            'fecha_inicio' => $this->institutionDay(70),
            'fecha_fin' => $this->institutionDay(200),
            'semanas_lectivas' => 16,
        ]);
        SyllabusProcess::query()->create([
            'periodo_academico_id' => $period->id,
            'inicia_en' => now()->addDays(70),
            'entrega_en' => now()->addDays(100),
            'estado' => SyllabusProcess::STATE_OPEN,
        ]);
        $this->actingAsAdministrator();
        $deadline()->assertJsonPath('deadline', null);
        $this->actingAsCoordinator();
        $deadline()->assertJsonPath('deadline.state', 'upcoming')->assertJsonPath('deadline.career_pending', true);
        $this->assertSame('cerrado', $process->fresh()?->estado);
    }

    public function test_the_structure_warning_only_shows_while_a_convocation_is_in_progress(): void
    {
        $warning = fn () => $this->actingAsAdministrator()->getJson(route('api.v1.admin.academic.index'))->assertOk()->json('convocation_in_progress');

        $this->assertFalse($warning());
        [$process] = $this->finalizedProcess();
        $this->assertFalse($warning());

        $process->update(['estado' => SyllabusProcess::STATE_PAUSED, 'finalizada_en' => null]);
        $this->assertTrue($warning());
    }

    public function test_the_coordinator_checklist_keeps_the_real_progress_after_finalizing(): void
    {
        $this->finalizedProcess();

        $steps = collect($this->actingAsCoordinator()->getJson(route('api.v1.dashboard.show'))->assertOk()->json('setup.steps'))->keyBy('key');

        $this->assertTrue($steps['curriculum']['done']);
        $this->assertTrue($steps['scheduled_subjects']['done']);
        $this->assertTrue($steps['parallels']['done']);
        $this->assertTrue($steps['teachers']['done']);
    }

    public function test_a_career_project_change_warns_and_arrives_only_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $stored = $this->storedSyllabi();
        $field = app(SubjectProfileFields::class)->fields()->firstOrFail();

        $this->assertWarned($this->saveProfile($subject, 'Ficha cambiada con la convocatoria finalizada'), $process);
        $this->saveProfile($subject, 'Ficha cambiada con la convocatoria finalizada', ['confirm_purge' => true])->assertOk();

        $this->assertSame($stored, $this->storedSyllabi());
        $this->assertStringNotContainsString('Ficha cambiada', FreeText::plainText($draft->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor));

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $fresh = $draft->fresh();
        $this->assertSame('borrador', $fresh?->estado);
        $this->assertStringContainsString('Ficha cambiada con la convocatoria finalizada', FreeText::plainText($fresh?->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor));
    }

    public function test_a_response_built_for_a_teacher_field_warns_and_arrives_only_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $field = FieldDefinition::query()->where('clave', 'metodologia')->firstOrFail();
        $other = FieldDefinition::query()->where('clave', 'recursos_medios')->firstOrFail();
        $written = $this->filledTemplateValue($other, 'Recursos que escribió el docente');
        FieldValue::query()->where('silabo_id', $draft->id)->where('definicion_campo_id', $other->id)->update(['valor' => json_encode($written)]);
        $built = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [
            ['type' => 'response', 'attrs' => ['kind' => 'una', 'options' => [['const' => 'o1', 'title' => 'Aula invertida'], ['const' => 'o2', 'title' => 'Proyectos']]]],
        ]]]];
        $save = fn (array $extra = []): TestResponse => $this->actingAsCoordinator()->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => [$field->id => $built], ...$extra]);

        $this->assertWarned($save(), $process);
        $save(['confirm_purge' => true])->assertOk();
        $this->assertSame([], TemplateDocument::nodes($draft->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor, 'response'));

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $fresh = $draft->fresh();
        $this->assertSame('borrador', $fresh?->estado);
        $this->assertSame('una', TemplateDocument::nodes($fresh?->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor, 'response')[0]['attrs']['kind']);
        $this->assertStringContainsString('Recursos que escribió el docente', FreeText::plainText($fresh?->values()->where('definicion_campo_id', $other->id)->firstOrFail()->valor));
    }

    public function test_saving_the_same_career_project_does_not_warn(): void
    {
        [, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $field = app(SubjectProfileFields::class)->fields()->firstOrFail();
        $current = app(SubjectProfileFields::class)->values($subject->id)[$field->id] ?? null;

        $this->actingAsCoordinator()->putJson(route('api.v1.academic.subjects.profile.update', $subject), ['values' => [$field->id => $current]])->assertOk();
    }

    public function test_a_career_project_change_makes_a_sent_syllabus_ask_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $sent = $this->sentSyllabus($draft);
        $this->saveProfile($subject, 'Ficha cambiada', ['confirm_purge' => true])->assertOk();

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts.to_draft', 1);

        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();
        $this->assertSame('borrador', $sent->fresh()?->estado);
    }

    public function test_a_new_teacher_does_not_erase_the_approved_syllabus_and_restarts_it_only_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $approved = Syllabus::query()->where('estado', 'aprobado')->sole();
        $assignment = TeacherAssignment::query()->where('paralelo_id', $approved->paralelo_id)->firstOrFail();
        $replacement = $this->createTeacher('suplente@silabos.test');
        $stored = $this->storedSyllabi();

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.destroy', ['asignacion_docente', $assignment->id]))->assertSuccessful();
        $this->assertWarned($this->assignTeacher($replacement, $assignment->paralelo_id), $process);
        $this->assignTeacher($replacement, $assignment->paralelo_id, ['confirm_purge' => true])->assertSuccessful();

        $this->assertSame($stored, $this->storedSyllabi());
        $this->assertSame('aprobado', $approved->fresh()?->estado);

        $data = $this->storedData();
        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts', ['to_draft' => 0, 'restarted' => 1, 'to_coordination' => 0]);
        $this->assertSame($data, $this->storedData());

        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $approved->id]);
        $fresh = Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->sole();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertSame([$replacement->id], $fresh->teachers()->pluck('usuarios.id')->all());
        $this->assertSame('borrador', $draft->fresh()?->estado);
    }

    public function test_replacing_a_teacher_warns_first_and_cancelling_leaves_the_assignment_as_it_was(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $assignment = TeacherAssignment::query()->where('paralelo_id', $draft->paralelo_id)->firstOrFail();
        $replacement = $this->createTeacher('suplente@silabos.test');
        $syllabi = $this->storedSyllabi();
        $data = $this->storedData();

        $this->assertWarned($this->replaceTeacher($replacement, $draft->paralelo_id), $process);

        $this->assertSame($data, $this->storedData());
        $this->assertTrue($assignment->fresh()?->activo);
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk();

        $this->replaceTeacher($replacement, $draft->paralelo_id, ['confirm_purge' => true])->assertSuccessful();

        $this->assertNull($assignment->fresh());
        $this->assertSame([$replacement->id], $draft->fresh()?->teachers()->pluck('usuarios.id')->all());
        $this->assertSame($syllabi, $this->storedSyllabi());
    }

    public function test_replacing_a_teacher_with_a_rejected_new_one_keeps_the_current_assignment(): void
    {
        [, $draft] = $this->finalizedProcess();
        $assignment = TeacherAssignment::query()->where('paralelo_id', $draft->paralelo_id)->firstOrFail();
        $unverified = $this->createTeacher('sin-verificar@silabos.test');
        $unverified->forceFill(['correo_verificado_en' => null])->save();

        $this->replaceTeacher($unverified, $draft->paralelo_id, ['confirm_purge' => true])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->assertTrue($assignment->fresh()?->activo);
    }

    public function test_a_new_teacher_restarts_a_draft_when_reopening_after_confirming(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $assignment = TeacherAssignment::query()->where('paralelo_id', $draft->paralelo_id)->firstOrFail();
        $replacement = $this->createTeacher('suplente@silabos.test');
        $stored = $this->storedSyllabi();

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.destroy', ['asignacion_docente', $assignment->id]))->assertSuccessful();
        $this->assignTeacher($replacement, $assignment->paralelo_id, ['confirm_purge' => true])->assertSuccessful();
        $this->assertSame($stored, $this->storedSyllabi());
        $data = $this->storedData();

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts', ['to_draft' => 0, 'restarted' => 1, 'to_coordination' => 0]);
        $this->assertSame($data, $this->storedData());

        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $draft->id]);
        $fresh = Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->sole();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertSame($draft->convocatoria_id, $fresh->convocatoria_id);
        $this->assertSame([$replacement->id], $fresh->teachers()->pluck('usuarios.id')->all());
    }

    public function test_a_teacher_assigned_before_finalizing_is_not_a_pending_change(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        TeacherAssignment::query()->where('paralelo_id', $draft->paralelo_id)->update(['asignado_en' => now()->subDay()]);

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $this->assertSame('borrador', $draft->fresh()?->estado);
        $this->assertDatabaseHas('silabos', ['silabo_id' => $draft->id]);
    }

    public function test_a_new_parallel_warns_in_bulk_and_gets_its_syllabus_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $stored = $this->storedSyllabi();
        $payload = ['subject_id' => $subject->id, 'codes' => ['Z', 'Y'], 'shift' => 'nocturna', 'user_id' => $this->teacher->id];

        $this->assertWarned($this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.parallels.store'), $payload), $process);
        $this->assertSame(0, Parallel::query()->where('asignatura_id', $subject->id)->whereIn('codigo_paralelo', ['Z', 'Y'])->count());

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.parallels.store'), [...$payload, 'confirm_purge' => true])->assertSuccessful();
        $this->assertSame($stored, $this->storedSyllabi());
        $parallel = Parallel::query()->where('asignatura_id', $subject->id)->where('codigo_paralelo', 'Z')->firstOrFail();

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $created = Syllabus::query()->where('paralelo_id', $parallel->id)->sole();
        $this->assertSame($draft->convocatoria_id, $created->convocatoria_id);
        $this->assertSame('sin_iniciar', $created->estado);
        $this->assertSame('Z', data_get($created->contexto_academico, 'parallel.code'));
        $this->assertArrayNotHasKey('closed_document', $created->contexto_academico);
        $this->assertSame([$this->teacher->id], $created->teachers()->pluck('usuarios.id')->all());
        $this->assertSame('borrador', $draft->fresh()?->estado);
    }

    public function test_reopening_skips_a_new_parallel_without_teacher(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $orphan = $draft->parallel->replicate();
        $orphan->codigo = 'SIN-DOCENTE';
        $orphan->save();

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);
        $this->assertSame(0, Syllabus::query()->where('paralelo_id', $orphan->id)->count());
        $this->assertSame(1, Syllabus::query()->forSubjects([$subject->id])->count());
    }

    public function test_a_parallel_change_arrives_only_when_reopening_and_a_sent_syllabus_is_restarted_after_confirming(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $approved = Syllabus::query()->where('estado', 'aprobado')->sole();
        $stored = $this->storedSyllabi();
        $update = fn (Parallel $parallel, array $extra = []): TestResponse => $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $parallel->id), ['code' => $parallel->codigo, 'shift' => 'nocturna', ...$extra]);

        $this->assertWarned($update($draft->parallel), $process);
        $update($draft->parallel, ['confirm_purge' => true])->assertSuccessful();
        $update($approved->parallel, ['confirm_purge' => true])->assertSuccessful();
        $this->assertSame($stored, $this->storedSyllabi());

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts.restarted', 1);
        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();

        $fresh = $draft->fresh();
        $this->assertSame('nocturna', data_get($fresh?->contexto_academico, 'parallel.shift'));
        $this->assertSame('borrador', $fresh?->estado);
        $this->assertDatabaseMissing('silabos', ['silabo_id' => $approved->id]);
        $restarted = Syllabus::query()->where('paralelo_id', $approved->paralelo_id)->sole();
        $this->assertSame('sin_iniciar', $restarted->estado);
        $this->assertSame('nocturna', data_get($restarted->contexto_academico, 'parallel.shift'));
    }

    public function test_deleting_a_parallel_never_removes_a_syllabus_of_a_finalized_process(): void
    {
        [, $draft] = $this->finalizedProcess();
        $parallel = $draft->parallel->replicate();
        $parallel->codigo = 'SIN-INICIAR';
        $parallel->save();
        Syllabus::query()->create([
            'convocatoria_id' => $draft->convocatoria_id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => $draft->contexto_academico,
            'estado' => 'sin_iniciar',
        ]);
        $stored = $this->storedSyllabi();

        $this->actingAsCoordinator()->deleteJson(route('api.v1.coordination.academic.parallels.destroy', $parallel->id))
            ->assertUnprocessable()->assertJsonValidationErrors('record');

        $this->assertSame($stored, $this->storedSyllabi());
    }

    public function test_a_new_coordinator_warns_and_receives_the_approvals_only_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $approved = Syllabus::query()->where('estado', 'aprobado')->sole();
        $career = Career::query()->findOrFail($draft->convocation->carrera_id);
        $incoming = User::query()->create(['nombre' => 'COORDINADOR ENTRANTE', 'correo_electronico' => 'entrante@silabos.test', 'contrasena' => 'Temporal-2026!', 'activo' => true]);
        $replace = fn (array $extra = []): TestResponse => $this->actingAsAdministrator()->patchJson(route('api.v1.admin.users.update', $incoming), [
            'nombre' => $incoming->nombre, 'correo_electronico' => $incoming->correo_electronico,
            'role_code' => 'coordinador', 'career_id' => $career->id, 'replace_coordinator' => true, ...$extra,
        ]);
        $stored = $this->storedSyllabi();

        $this->assertWarned($replace(), $process);
        $this->assertSame(0, $incoming->roleAssignments()->count());

        $replace(['confirm_purge' => true])->assertOk();
        $this->assertSame($stored, $this->storedSyllabi());
        $this->assertSame('aprobado', $approved->fresh()?->estado);

        $data = $this->storedData();
        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts', ['to_draft' => 0, 'restarted' => 0, 'to_coordination' => 1]);
        $this->assertSame($data, $this->storedData());

        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();

        $this->assertSame('en_revision', $approved->fresh()?->estado);
        $this->assertDatabaseMissing('aprobaciones', ['revision_silabo_id' => SyllabusRevision::query()->where('silabo_id', $approved->id)->value('revision_silabo_id')]);
    }

    public function test_a_new_coordinator_without_decided_syllabi_does_not_warn(): void
    {
        [, $draft] = $this->finalizedProcess();
        $career = Career::query()->findOrFail($draft->convocation->carrera_id);
        $incoming = User::query()->create(['nombre' => 'COORDINADOR ENTRANTE', 'correo_electronico' => 'entrante@silabos.test', 'contrasena' => 'Temporal-2026!', 'activo' => true]);

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.users.update', $incoming), [
            'nombre' => $incoming->nombre, 'correo_electronico' => $incoming->correo_electronico,
            'role_code' => 'coordinador', 'career_id' => $career->id, 'replace_coordinator' => true,
        ])->assertOk();
    }

    public function test_a_career_name_change_warns_and_arrives_only_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $career = Career::query()->findOrFail($draft->convocation->carrera_id);
        $stored = $this->storedSyllabi();
        $update = fn (array $extra = []): TestResponse => $this->actingAsAdministrator()->patchJson(route('api.v1.admin.academic.update', ['carrera', $career->id]), [
            'faculty_id' => $career->facultad_id, 'modality' => $career->modalidad->value, 'code' => $career->codigo_carrera,
            'nombre' => 'Carrera Renombrada', ...$extra,
        ]);

        $this->assertWarned($update(), $process);
        $this->assertNotSame('Carrera Renombrada', $career->fresh()?->nombre);
        $update(['confirm_purge' => true])->assertSuccessful();

        $this->assertSame('Carrera Renombrada', $career->fresh()?->nombre);
        $this->assertSame($stored, $this->storedSyllabi());

        $this->extend($process, $this->institutionDay(20))->assertOk();
        $this->assertSame('Carrera Renombrada', data_get($draft->fresh()?->contexto_academico, 'career.name'));
    }

    public function test_a_faculty_name_change_warns_but_its_libraries_do_not(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $faculty = Career::query()->findOrFail($draft->convocation->carrera_id)->faculty;
        $payload = ['nombre' => $faculty->nombre, 'campus' => $faculty->campus->value];
        $update = fn (array $changes): TestResponse => $this->actingAsAdministrator()->patchJson(route('api.v1.admin.academic.update', ['facultad', $faculty->id]), [...$payload, ...$changes]);
        $stored = $this->storedSyllabi();

        $update(['catalog_libraries' => ['BIB1']])->assertSuccessful();
        $this->assertWarned($update(['nombre' => 'Facultad Renombrada']), $process);
        $update(['nombre' => 'Facultad Renombrada', 'confirm_purge' => true])->assertSuccessful();

        $this->assertSame($stored, $this->storedSyllabi());
        $this->extend($process, $this->institutionDay(20))->assertOk();
        $this->assertSame('Facultad Renombrada', data_get($draft->fresh()?->contexto_academico, 'career.faculty'));
    }

    public function test_a_period_change_warns_and_restarts_the_syllabi_only_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $period = AcademicPeriod::query()->firstOrFail();
        $stored = $this->storedSyllabi();
        $payload = ['starts_on' => $period->fecha_inicio->toDateString(), 'ends_on' => $period->fecha_fin->toDateString(), 'teaching_weeks' => $period->semanas_lectivas + 1];
        $update = fn (array $extra = []): TestResponse => $this->actingAsAdministrator()->patchJson(route('api.v1.admin.academic.update', ['periodo', $period->id]), [...$payload, ...$extra]);

        $this->assertWarned($update(), $process);
        $update(['confirm_purge' => true])->assertSuccessful();
        $this->assertSame($stored, $this->storedSyllabi());

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('counts', ['to_draft' => 0, 'restarted' => 2, 'to_coordination' => 0]);
        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $draft->id]);
        $this->assertSame($period->semanas_lectivas + 1, data_get(Syllabus::query()->where('paralelo_id', $draft->paralelo_id)->sole()->contexto_academico, 'scheduled_subject.teaching_weeks'));
    }

    public function test_a_period_change_asks_before_restarting_a_draft_with_teacher_text(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $period = AcademicPeriod::query()->firstOrFail();
        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.academic.update', ['periodo', $period->id]), [
            'starts_on' => $period->fecha_inicio->toDateString(), 'ends_on' => $period->fecha_fin->toDateString(),
            'teaching_weeks' => $period->semanas_lectivas + 1, 'confirm_purge' => true,
        ])->assertSuccessful();
        $data = $this->storedData();

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts', ['to_draft' => 0, 'restarted' => 1, 'to_coordination' => 0]);

        $this->assertSame($data, $this->storedData());
        $this->assertSame('borrador', $draft->fresh()?->estado);
    }

    public function test_an_unstarted_syllabus_that_is_restarted_does_not_ask(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $draft->update(['estado' => 'sin_iniciar', 'iniciado_en' => null]);
        $subject = $draft->parallel->subject;
        $this->updateSubject($subject, ['generates_syllabus' => false, 'confirm_purge' => true])->assertSuccessful();

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $draft->id]);
    }

    public function test_a_subject_that_stops_generating_syllabi_loses_them_only_when_reopening_after_confirming(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $stored = $this->storedSyllabi();

        $this->assertWarned($this->updateSubject($subject, ['generates_syllabus' => false]), $process);
        $this->updateSubject($subject, ['generates_syllabus' => false, 'confirm_purge' => true])->assertSuccessful();
        $this->assertSame($stored, $this->storedSyllabi());
        $data = $this->storedData();

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes')
            ->assertJsonPath('counts', ['to_draft' => 0, 'restarted' => 1, 'to_coordination' => 0]);
        $this->assertSame($data, $this->storedData());

        $this->extend($process, $this->institutionDay(20), ['confirmar_cambios' => true])->assertOk();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $draft->id]);
    }

    public function test_a_template_change_and_pending_changes_are_confirmed_together(): void
    {
        [$process, $draft] = $this->finalizedProcess(withApproved: true);
        $subject = $draft->parallel->subject;
        $this->updateSubject($subject, ['horas_aa' => (float) $subject->horas_aa + 16, 'confirm_purge' => true])->assertSuccessful();
        SyllabusTemplate::query()->firstOrFail()->forceFill(['plantilla_modificada_en' => now()->addMinute()])->save();
        $data = $this->storedData();

        $this->extend($process, $this->institutionDay(20))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'plantilla_cambiada')
            ->assertJsonPath('pending_counts.to_draft', 1);
        $this->assertSame($data, $this->storedData());

        $this->extend($process, $this->institutionDay(20), ['confirmar_rehacer' => true])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cambios_pendientes');
        $this->assertSame($data, $this->storedData());

        $this->extend($process, $this->institutionDay(20), ['confirmar_rehacer' => true, 'confirmar_cambios' => true])->assertOk();
        $this->assertSame(SyllabusProcess::STATE_OPEN, $process->fresh()?->estado);
    }

    public function test_an_unsent_syllabus_of_a_finalized_process_still_shows_its_form_read_only(): void
    {
        [, $draft] = $this->finalizedProcess();

        $syllabus = $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk()->json('syllabus');
        $fields = collect($syllabus['sections'])->flatMap(fn (array $section): array => $section['fields']);

        $this->assertFalse($syllabus['editable']);
        $this->assertNotEmpty($fields->where('quien_llena', 'carrera'));
        $this->assertTrue(collect($syllabus['sections'])->every(fn (array $section): bool => is_string($section['id'] ?? null) && $section['id'] !== ''));
        $this->assertTrue($fields->where('quien_llena', 'carrera')->every(fn (array $field): bool => $field['teacher_editable'] === true));
        $this->assertTrue($fields->where('quien_llena', 'institucional')->every(fn (array $field): bool => $field['teacher_editable'] === false));
        $this->assertTrue($fields->where('quien_llena', 'carrera')->every(fn (array $field): bool => is_array($field['template'] ?? null)));
    }

    /** @return array{SyllabusProcess, Syllabus} */
    private function finalizedProcess(bool $withApproved = false): array
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.store'), [
            'period_id' => AcademicPeriod::query()->valueOrFail('id'),
            'starts_at' => $this->institutionDay(),
            'due_at' => $this->institutionDay(10),
        ])->assertSuccessful();
        $process = SyllabusProcess::query()->firstOrFail();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), ['process_id' => $process->id])->assertSuccessful();
        $draft = Syllabus::query()->firstOrFail();
        $this->actingAsTeacher()->postJson(route('api.v1.teacher.syllabi.start', $draft))->assertSuccessful();
        if ($withApproved) {
            $this->approvedSyllabus($draft->fresh() ?? $draft);
        }
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.transition', [$process, 'cerrar']))->assertSuccessful();
        $this->assertSame(SyllabusProcess::STATE_CLOSED, $process->fresh()?->estado);
        $this->assertArrayHasKey('closed_document', $draft->fresh()?->contexto_academico ?? []);

        return [$process->fresh() ?? $process, $draft->fresh() ?? $draft];
    }

    private function sentSyllabus(Syllabus $draft): Syllabus
    {
        return $this->approvedSyllabus($draft, approved: false);
    }

    private function approvedSyllabus(Syllabus $draft, bool $approved = true): Syllabus
    {
        $parallel = $draft->parallel->replicate();
        $parallel->codigo = 'APROBADO';
        $parallel->save();
        TeacherAssignment::query()->create(['usuario_id' => $this->teacher->id, 'paralelo_id' => $parallel->id, 'activo' => true]);
        $syllabus = Syllabus::query()->create([
            'convocatoria_id' => $draft->convocatoria_id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => [...$draft->contexto_academico, 'parallel' => [...(array) data_get($draft->contexto_academico, 'parallel'), 'id' => $parallel->id, 'code' => 'APROBADO']],
            'estado' => $approved ? 'aprobado' : 'en_revision',
        ]);
        foreach (FieldValue::query()->where('silabo_id', $draft->id)->get() as $value) {
            FieldValue::query()->create(['silabo_id' => $syllabus->id, 'definicion_campo_id' => $value->definicion_campo_id, 'valor' => $value->valor]);
        }
        $revision = SyllabusRevision::query()->create([
            'silabo_id' => $syllabus->id,
            'numero_revision' => 1,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 1,
            'fotografia' => $draft->contexto_academico,
            'huella_sha256' => str_repeat('a', 64),
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now(),
        ]);
        if ($approved) {
            Approval::query()->create([
                'revision_silabo_id' => $revision->id,
                'clave_idempotencia' => (string) Str::uuid(),
                'huella_sha256' => str_repeat('a', 64),
                'aprobado_por' => $this->coordinator->id,
                'aprobado_en' => now(),
            ]);
        }

        return $syllabus;
    }

    /** @param  array<string, mixed>  $changes */
    private function updateSubject(Subject $subject, array $changes): TestResponse
    {
        return $this->actingAsCoordinator()->patchJson(route('api.v1.coordination.academic.update', ['asignatura', $subject->id]), [
            'code' => $subject->codigo_asignatura,
            'nombre' => $subject->nombre,
            'cycle' => $subject->ciclo,
            'position' => $subject->orden_en_ciclo,
            'organization_unit' => $subject->unidad_organizacion_curricular?->value ?? 'basica',
            'modality' => $subject->modalidad?->value,
            'creditos' => $subject->creditos,
            'horas_totales' => $subject->horas_totales,
            'horas_ac' => $subject->horas_ac,
            'horas_pae' => $subject->horas_pae,
            'horas_aa' => $subject->horas_aa,
            ...$changes,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function saveProfile(Subject $subject, string $text, array $extra = []): TestResponse
    {
        $field = app(SubjectProfileFields::class)->fields()->firstOrFail();

        return $this->actingAsCoordinator()->putJson(route('api.v1.academic.subjects.profile.update', $subject), [
            'values' => [$field->id => $this->filledTemplateValue($field, $text)],
            ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function assignTeacher(User $teacher, string $parallelId, array $extra = []): TestResponse
    {
        return $this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
            'user_id' => $teacher->id, 'parallel_id' => $parallelId, ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function replaceTeacher(User $teacher, string $parallelId, array $extra = []): TestResponse
    {
        return $this->actingAsCoordinator()->putJson(route('api.v1.coordination.academic.parallels.teacher.update', $parallelId), ['user_id' => $teacher->id, ...$extra]);
    }

    private function assertWarned(TestResponse $response, SyllabusProcess $process): void
    {
        $period = $process->academicPeriod()->valueOrFail('codigo_periodo_academico');
        $response->assertUnprocessable()
            ->assertJsonPath('errors.purge_mode.0', 'convocatoria_finalizada')
            ->assertJsonPath('errors.purge_required.0', "La convocatoria {$period} ya finalizó. Este cambio no afecta sus sílabos: conservan los datos que tenían. Se aplicará en la próxima convocatoria, o en esta si la reabre con una prórroga. Evite estos cambios hasta abrir la próxima convocatoria, salvo que sea necesario.");
    }

    private function storedSyllabi(): string
    {
        return $this->hashOf(['silabos', 'revisiones_silabo', 'aprobaciones', 'revisiones_coordinacion', 'envios_biblioteca', 'firmas_silabo', 'valores_campo'], onlySyllabusValues: true);
    }

    private function storedData(): string
    {
        return $this->hashOf([
            'silabos', 'revisiones_silabo', 'aprobaciones', 'revisiones_coordinacion', 'envios_biblioteca', 'firmas_silabo', 'valores_campo',
            'convocatorias_universidad', 'convocatorias_carreras', 'notificaciones', 'eventos_auditoria', 'asignaciones_paralelo', 'asignaciones_rol',
            'paralelos', 'asignaturas', 'carreras', 'facultades', 'periodos_academicos',
        ]);
    }

    /** @param  list<string>  $tables */
    private function hashOf(array $tables, bool $onlySyllabusValues = false): string
    {
        return md5((string) json_encode(collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->when($onlySyllabusValues && $table === 'valores_campo', fn ($query) => $query->whereNotNull('silabo_id'))
                ->get()->map(fn (object $row): string => (string) json_encode((array) $row))->sort()->values()->all(),
        ])->all()));
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

    /** @param  array<string, mixed>  $extra */
    private function extend(SyllabusProcess $process, string $day, array $extra = []): TestResponse
    {
        return $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $day, ...$extra]);
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

    private function actingAsTeacher(): static
    {
        $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacher->roleAssignments()->firstOrFail()->id]);

        return $this;
    }
}
