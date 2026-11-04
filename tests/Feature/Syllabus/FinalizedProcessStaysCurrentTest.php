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
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
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

class FinalizedProcessStaysCurrentTest extends TestCase
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

    public function test_a_curriculum_change_reaches_the_syllabi_of_a_finalized_process_until_classes_end(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;
        $hours = (float) $subject->horas_aa + 16;

        $this->updateSubject($subject, ['horas_aa' => $hours])->assertSuccessful();

        $fresh = $draft->fresh();
        $this->assertSame('borrador', $fresh?->estado);
        $this->assertEquals($hours, data_get($fresh?->contexto_academico, 'subject.hours_aa'));
        $this->assertEquals($hours, data_get($fresh?->contexto_academico, 'closed_document.academic_context.subject.hours_aa'));
        $this->assertArrayNotHasKey('closed_document', (array) data_get($fresh?->contexto_academico, 'closed_document.academic_context'));
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk()
            ->assertJsonPath('syllabus.editable', false);

        $this->extend($process, $this->institutionDay(20))->assertOk();

        $reopened = $draft->fresh();
        $this->assertArrayNotHasKey('closed_document', $reopened?->contexto_academico ?? []);
        $this->assertEquals($hours, data_get($reopened?->contexto_academico, 'subject.hours_aa'));
        $this->actingAsTeacher()->getJson(route('api.v1.teacher.syllabi.show', $draft))->assertOk()
            ->assertJsonPath('syllabus.editable', true);
    }

    public function test_an_approved_syllabus_of_a_current_finalized_process_returns_to_draft_after_confirming(): void
    {
        [, $draft] = $this->finalizedProcess(withApproved: true);
        $approved = Syllabus::query()->where('estado', 'aprobado')->sole();
        $subject = $draft->parallel->subject;
        $hours = (float) $subject->horas_aa + 16;

        $this->updateSubject($subject, ['horas_aa' => $hours])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purge_required')
            ->assertJsonPath('errors.purge_count.0', '1');
        $this->assertSame('aprobado', $approved->fresh()?->estado);
        $this->assertNotEquals($hours, data_get($draft->fresh()?->contexto_academico, 'subject.hours_aa'));

        $this->updateSubject($subject, ['horas_aa' => $hours, 'confirm_purge' => true])->assertSuccessful();

        $fresh = $approved->fresh();
        $this->assertSame('borrador', $fresh?->estado);
        $this->assertDatabaseMissing('revisiones_silabo', ['silabo_id' => $approved->id]);
        $this->assertEquals($hours, data_get($fresh?->contexto_academico, 'subject.hours_aa'));
        $this->assertEquals($hours, data_get($fresh?->contexto_academico, 'closed_document.academic_context.subject.hours_aa'));
        $this->assertTrue(InternalNotification::query()->where('tipo', 'silabo.devuelto_borrador')->where('recurso_id', $approved->id)->exists());
        $this->assertSame(Convocation::STATE_CLOSED, $approved->convocation()->value('estado_convocatoria_carrera'));
    }

    public function test_once_classes_end_a_finalized_process_is_no_longer_touched(): void
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

    public function test_a_newer_process_ends_the_finalized_one_even_if_classes_continue(): void
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

    public function test_the_structure_notice_tells_an_open_convocation_from_a_finalized_one_until_classes_end(): void
    {
        $notice = fn () => $this->actingAsAdministrator()->getJson(route('api.v1.admin.academic.index'))->assertOk()->json('convocation_notice');

        $this->assertNull($notice());
        $this->finalizedProcess();
        $this->assertSame('finalized', $notice());

        $this->travelTo(CarbonImmutable::parse($this->institutionDay(61), 'America/Guayaquil')->setTime(12, 0));
        $this->assertNull($notice());
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

    public function test_a_career_project_change_reaches_a_current_finalized_process(): void
    {
        [, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;

        $this->saveProfile($subject, 'Ficha cambiada con la convocatoria finalizada')->assertOk();

        $field = app(SubjectProfileFields::class)->fields()->firstOrFail();
        $fresh = $draft->fresh();
        $this->assertSame('borrador', $fresh?->estado);
        $value = $fresh?->values()->where('definicion_campo_id', $field->id)->firstOrFail()->valor;
        $this->assertStringContainsString('Ficha cambiada con la convocatoria finalizada', FreeText::plainText($value));
        $snapshot = collect(data_get($fresh?->contexto_academico, 'closed_document.sections'))
            ->flatMap(fn (array $section): array => $section['fields'])
            ->firstWhere('definition_id', $field->id);
        $this->assertIsArray($snapshot);
        $this->assertStringContainsString('Ficha cambiada con la convocatoria finalizada', (string) json_encode($snapshot['document'], JSON_UNESCAPED_UNICODE));
    }

    public function test_a_new_teacher_restarts_the_syllabus_of_a_current_finalized_process(): void
    {
        [, $draft] = $this->finalizedProcess();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();
        $replacement = $this->createTeacher('suplente@silabos.test');

        $this->actingAsCoordinator()
            ->deleteJson(route('api.v1.coordination.academic.destroy', ['asignacion_docente', $assignment->id]))->assertSuccessful();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.store', 'asignacion_docente'), [
            'user_id' => $replacement->id, 'parallel_id' => $assignment->paralelo_id,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('silabos', ['silabo_id' => $draft->id]);
        $fresh = Syllabus::query()->where('paralelo_id', $assignment->paralelo_id)->sole();
        $this->assertSame('sin_iniciar', $fresh->estado);
        $this->assertSame($draft->convocatoria_id, $fresh->convocatoria_id);
        $this->assertSame([$replacement->id], $fresh->teachers()->pluck('usuarios.id')->all());
        $this->assertIsArray(data_get($fresh->contexto_academico, 'closed_document'));
    }

    public function test_a_new_parallel_of_a_current_finalized_process_gets_its_syllabus_when_reopening(): void
    {
        [$process, $draft] = $this->finalizedProcess();
        $subject = $draft->parallel->subject;

        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.parallels.store'), [
            'subject_id' => $subject->id, 'codes' => ['Z'], 'shift' => 'nocturna', 'user_id' => $this->teacher->id,
        ])->assertSuccessful();
        $parallel = Parallel::query()->where('asignatura_id', $subject->id)->where('codigo_paralelo', 'Z')->firstOrFail();
        $this->assertSame(0, Syllabus::query()->where('paralelo_id', $parallel->id)->count());

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

    public function test_a_parallel_change_refreshes_the_identification_of_a_current_finalized_process(): void
    {
        [, $draft] = $this->finalizedProcess();
        $parallel = $draft->parallel;

        $this->actingAsCoordinator()
            ->patchJson(route('api.v1.coordination.academic.parallels.update', $parallel->id), ['code' => $parallel->codigo, 'shift' => 'nocturna'])
            ->assertSuccessful();

        $fresh = $draft->fresh();
        $this->assertSame('nocturna', data_get($fresh?->contexto_academico, 'parallel.shift'));
        $this->assertSame('nocturna', data_get($fresh?->contexto_academico, 'closed_document.academic_context.parallel.shift'));
        $this->assertSame('borrador', $fresh?->estado);
    }

    public function test_a_new_coordinator_receives_the_approvals_of_a_current_finalized_process(): void
    {
        [, $draft] = $this->finalizedProcess(withApproved: true);
        $approved = Syllabus::query()->where('estado', 'aprobado')->sole();
        $career = Career::query()->findOrFail($draft->convocation->carrera_id);
        $incoming = User::query()->create(['nombre' => 'COORDINADOR ENTRANTE', 'correo_electronico' => 'entrante@silabos.test', 'contrasena' => 'Temporal-2026!', 'activo' => true]);

        $this->actingAsAdministrator()->patchJson(route('api.v1.admin.users.update', $incoming), [
            'nombre' => $incoming->nombre, 'correo_electronico' => $incoming->correo_electronico,
            'role_code' => 'coordinador', 'career_id' => $career->id, 'replace_coordinator' => true,
        ])->assertOk();

        $this->assertSame('en_revision', $approved->fresh()?->estado);
        $this->assertDatabaseMissing('aprobaciones', ['revision_silabo_id' => SyllabusRevision::query()->where('silabo_id', $approved->id)->value('revision_silabo_id')]);
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

    private function approvedSyllabus(Syllabus $draft): Syllabus
    {
        $parallel = $draft->parallel->replicate();
        $parallel->codigo = 'APROBADO';
        $parallel->save();
        TeacherAssignment::query()->create(['usuario_id' => $this->teacher->id, 'paralelo_id' => $parallel->id, 'activo' => true]);
        $approved = Syllabus::query()->create([
            'convocatoria_id' => $draft->convocatoria_id,
            'paralelo_id' => $parallel->id,
            'contexto_academico' => [...$draft->contexto_academico, 'parallel' => [...(array) data_get($draft->contexto_academico, 'parallel'), 'id' => $parallel->id, 'code' => 'APROBADO']],
            'estado' => 'aprobado',
        ]);
        $revision = SyllabusRevision::query()->create([
            'silabo_id' => $approved->id,
            'numero_revision' => 1,
            'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => 1,
            'fotografia' => $draft->contexto_academico,
            'huella_sha256' => str_repeat('a', 64),
            'enviado_por' => $this->teacher->id,
            'enviado_en' => now(),
        ]);
        Approval::query()->create([
            'revision_silabo_id' => $revision->id,
            'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => str_repeat('a', 64),
            'aprobado_por' => $this->coordinator->id,
            'aprobado_en' => now(),
        ]);

        return $approved;
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

    private function saveProfile(Subject $subject, string $text): TestResponse
    {
        $field = app(SubjectProfileFields::class)->fields()->firstOrFail();

        return $this->actingAsCoordinator()->putJson(route('api.v1.academic.subjects.profile.update', $subject), [
            'values' => [$field->id => $this->filledTemplateValue($field, $text)],
        ]);
    }

    private function storedSyllabi(): string
    {
        $tables = ['silabos', 'revisiones_silabo', 'aprobaciones', 'valores_campo'];

        return md5((string) json_encode(collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->when($table === 'valores_campo', fn ($query) => $query->whereNotNull('silabo_id'))
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

    private function extend(SyllabusProcess $process, string $day): TestResponse
    {
        return $this->actingAsAdministrator()->postJson(route('api.v1.admin.processes.deadline.extend', $process), ['due_at' => $day]);
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
