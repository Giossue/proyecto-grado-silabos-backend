<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsInternalNotifications;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

/**
 * I-39: relevar a un docente es la única forma de cambiar el docente de un paralelo. El
 * alcance es todos sus paralelos o uno solo. Los paralelos con sílabo siguen las reglas por
 * estado y exigen convocatoria abierta o pausada; los que no tienen sílabo solo cambian de
 * manos. Con un sílabo en revisión no se mueve nada.
 */
class TeacherReliefTest extends TestCase
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

    public function test_the_relief_moves_every_parallel_with_and_without_syllabus(): void
    {
        $syllabus = $this->openedSyllabus();
        // Un segundo paralelo del mismo docente, abierto después de la convocatoria: sin sílabo.
        $extra = $this->extraParallelFor($this->teacher);

        $this->relieve()->assertSuccessful();

        $this->assertDatabaseHas('colaboradores_silabo', ['silabo_id' => $syllabus->id, 'usuario_id' => $this->replacement->id]);
        $this->assertDatabaseMissing('colaboradores_silabo', ['silabo_id' => $syllabus->id, 'usuario_id' => $this->teacher->id]);
        $this->assertSame(0, TeacherAssignment::query()->forUser($this->teacher->id)->where('activo', true)->count());
        $this->assertTrue(TeacherAssignment::query()
            ->forUser($this->replacement->id)
            ->where('paralelo_id', $extra->id)
            ->where('activo', true)
            ->exists());
        $this->assertDatabaseHas('eventos_auditoria', ['accion_evento_auditoria' => 'docente.relevo_global', 'recurso_id' => $this->teacher->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['accion_evento_auditoria' => 'silabo.docente_transferido', 'recurso_id' => $syllabus->id]);

        // Un solo resumen por persona, no un aviso por sílabo.
        $this->assertNotified($this->replacement->id, 'docente.relevo_recibido', "2 paralelos y 1 sílabo que tenía {$this->teacher->nombre}");
        $this->assertNotified($this->teacher->id, 'docente.relevo_entregado', "asignó sus 2 paralelos y 1 sílabo a {$this->replacement->nombre}");
        $this->assertNotNotified($this->replacement->id, 'silabo.docente_asignado');
        $this->assertNotNotified($this->teacher->id, 'silabo.docente_retirado');

        // Repetirlo ya no encuentra paralelos: se dice, no se duplica.
        $this->relieve()->assertJsonValidationErrors('outgoing_user_id');
    }

    public function test_a_syllabus_under_review_blocks_the_whole_relief_and_names_the_subject(): void
    {
        $syllabus = $this->submittedSyllabus();
        $extra = $this->extraParallelFor($this->teacher);

        $this->relieve()->assertJsonValidationErrors('outgoing_user_id');

        $this->assertDatabaseHas('colaboradores_silabo', ['silabo_id' => $syllabus->id, 'usuario_id' => $this->teacher->id]);
        $this->assertTrue(TeacherAssignment::query()->forUser($this->teacher->id)->where('paralelo_id', $extra->id)->where('activo', true)->exists());
        $this->assertSame(0, TeacherAssignment::query()->forUser($this->replacement->id)->count());
    }

    public function test_the_relief_can_target_a_single_parallel(): void
    {
        $syllabus = $this->openedSyllabus();
        $extra = $this->extraParallelFor($this->teacher);
        $extraAssignment = TeacherAssignment::query()->forUser($this->teacher->id)->where('paralelo_id', $extra->id)->firstOrFail();

        $this->relieve(assignmentId: $extraAssignment->id)->assertSuccessful();

        $this->assertFalse($extraAssignment->fresh()->activo);
        $this->assertTrue(TeacherAssignment::query()->forUser($this->replacement->id)->where('paralelo_id', $extra->id)->where('activo', true)->exists());
        // El paralelo con sílabo queda como estaba.
        $this->assertDatabaseHas('colaboradores_silabo', ['silabo_id' => $syllabus->id, 'usuario_id' => $this->teacher->id]);
        $this->assertNotified($this->replacement->id, 'docente.relevo_recibido', '1 paralelo que tenía');

        // Un paralelo que ya no es del saliente no se releva.
        $this->relieve(assignmentId: $extraAssignment->id)->assertJsonValidationErrors('teacher_assignment_id');
    }

    public function test_an_unsubmitted_draft_is_discarded_without_completion_percentage(): void
    {
        $syllabus = $this->validDraft();
        $this->assertGreaterThan(0, $syllabus->values()->where('heredado', false)->count());

        $this->relieve()->assertSuccessful();

        $syllabus->refresh();
        $this->assertSame('sin_iniciar', $syllabus->estado);
        $this->assertSame(0, $syllabus->values()->count());
        $this->assertSame(0, $syllabus->rows()->count());
        $event = AuditEvent::query()->where('accion', 'silabo.docente_transferido')->firstOrFail();
        $this->assertSame('borrador', $event->metadatos['previous_state']);
        $this->assertArrayNotHasKey('discarded_completion', $event->metadatos);
    }

    public function test_an_approved_syllabus_is_reopened_and_keeps_the_approved_revision_intact(): void
    {
        $syllabus = $this->submittedSyllabus();
        $revision = $syllabus->revisions()->orderByDesc('numero_revision')->firstOrFail();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.reviews.approve', $revision), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revisionCount = $syllabus->revisions()->count();

        $this->relieve()->assertSuccessful();

        $syllabus->refresh();
        $this->assertSame('correccion_solicitada', $syllabus->estado);
        $this->assertSame($revisionCount, $syllabus->revisions()->count());
        $this->assertDatabaseCount('aprobaciones', 1);
        $this->assertDatabaseHas('reaperturas', ['silabo_id' => $syllabus->id]);
        $this->assertDatabaseHas('colaboradores_silabo', ['silabo_id' => $syllabus->id, 'usuario_id' => $this->replacement->id]);
        $this->assertGreaterThan(0, $syllabus->values()->count());
    }

    public function test_a_closed_convocation_blocks_the_relief_of_its_syllabi(): void
    {
        $syllabus = $this->openedSyllabus();
        $convocation = Convocation::query()->firstOrFail();
        $convocation->estado = Convocation::STATE_CLOSED;
        $convocation->save();

        $this->relieve()->assertJsonValidationErrors('outgoing_user_id');

        $this->assertDatabaseHas('colaboradores_silabo', ['silabo_id' => $syllabus->id, 'usuario_id' => $this->teacher->id]);
        $this->assertSame(0, TeacherAssignment::query()->forUser($this->replacement->id)->count());
    }

    public function test_global_relief_leaves_finished_period_assignments_in_history(): void
    {
        $syllabus = $this->openedSyllabus();
        AcademicPeriod::query()->update([
            'fecha_inicio' => '2025-05-01',
            'fecha_fin' => '2026-03-31',
        ]);

        $this->relieve()->assertJsonValidationErrors('outgoing_user_id');

        $this->assertDatabaseHas('colaboradores_silabo', [
            'silabo_id' => $syllabus->id,
            'usuario_id' => $this->teacher->id,
        ]);
        $this->assertTrue(TeacherAssignment::query()->forUser($this->teacher->id)->where('activo', true)->exists());
        $this->assertSame(0, TeacherAssignment::query()
            ->forUser($this->replacement->id)
            ->count());
    }

    public function test_pausing_and_resuming_the_convocation_notifies_its_teachers(): void
    {
        $this->openedSyllabus();
        $convocation = Convocation::query()->with('career')->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']), [
                'reason' => 'Corregir la malla antes de continuar.',
            ])
            ->assertSuccessful();
        $this->assertNotified($this->teacher->id, 'convocatoria.pausada', "La convocatoria de {$convocation->career->nombre} está en pausa.");

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertSuccessful();
        $this->assertNotified($this->teacher->id, 'convocatoria.reanudada', 'se reanudó');
        $this->assertNotNotified($this->coordinator->id, 'convocatoria.pausada');
    }

    public function test_coordination_only_changes_a_teacher_through_the_relief(): void
    {
        $this->openedSyllabus();
        $assignment = TeacherAssignment::query()->forUser($this->teacher->id)->firstOrFail();

        // Ni edición en sitio ni activación/desactivación: solo relevo, alta o baja sin uso.
        $this->actingAsCoordinator()
            ->patchJson("/api/v1/coordination/academic/asignacion_docente/{$assignment->id}", [
                'user_id' => $this->replacement->id,
                'parallel_id' => $assignment->paralelo_id,
            ])
            ->assertMethodNotAllowed();
        $this->actingAsCoordinator()
            ->patchJson("/api/v1/coordination/academic/asignacion_docente/{$assignment->id}/status", ['active' => false])
            ->assertNotFound();
        $this->actingAsCoordinator()
            ->postJson('/api/v1/coordination/academic/paralelo', ['scheduled_subject_id' => $assignment->parallel->programacion_asignatura_id, 'code' => 'Q'])
            ->assertNotFound();
        $this->actingAsCoordinator()
            ->deleteJson("/api/v1/coordination/academic/paralelo/{$assignment->paralelo_id}")
            ->assertNotFound();

        $this->assertTrue($assignment->fresh()->activo);
        $this->assertSame($this->teacher->id, $assignment->fresh()->roleAssignment->usuario_id);
    }

    public function test_the_replacement_must_teach_in_the_career_and_only_coordination_relieves(): void
    {
        $this->openedSyllabus();
        $outsider = User::query()->create(['nombre' => 'Docente ajeno', 'correo_electronico' => 'ajeno@silabos.test', 'contrasena' => 'Temporal-2026!', 'activo' => true]);

        $this->relieve($outsider->id)->assertJsonValidationErrors('incoming_user_id');

        $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacherContext->id])
            ->postJson(route('api.v1.coordination.academic.teachers.relieve'), $this->payload())
            ->assertForbidden();
    }

    public function test_archiving_a_teacher_with_syllabi_in_progress_is_refused_until_the_relief(): void
    {
        $this->validDraft();

        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $this->teacher), [
                'nombre' => $this->teacher->nombre,
                'correo_electronico' => $this->teacher->correo_electronico,
                'active' => 0,
            ])
            ->assertJsonValidationErrors('active');
        $this->assertTrue($this->teacher->fresh()->activo);

        $this->relieve()->assertSuccessful()->assertSuccessful();

        // Sin sílabos en curso, archivar cierra lo que quedara vigente.
        $this->actingAsAdministrator()
            ->patchJson(route('api.v1.admin.users.update', $this->teacher), [
                'nombre' => $this->teacher->nombre,
                'correo_electronico' => $this->teacher->correo_electronico,
                'active' => 0,
            ])
            ->assertSuccessful()
            ->assertSuccessful();
        $this->assertFalse($this->teacher->fresh()->activo);
    }

    private function relieve(?string $incomingId = null, ?string $assignmentId = null): TestResponse
    {
        return $this->actingAsCoordinator()->postJson(route('api.v1.coordination.academic.teachers.relieve'), $this->payload($incomingId, $assignmentId));
    }

    /** @return array<string, string> */
    private function payload(?string $incomingId = null, ?string $assignmentId = null): array
    {
        return array_filter([
            'outgoing_user_id' => $this->teacher->id,
            'incoming_user_id' => $incomingId ?? $this->replacement->id,
            'teacher_assignment_id' => $assignmentId,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function extraParallelFor(User $teacher): Parallel
    {
        $scheduledSubject = ScheduledSubject::query()->firstOrFail();
        $parallel = Parallel::query()->create(['programacion_asignatura_id' => $scheduledSubject->id, 'codigo' => 'Z']);
        TeacherAssignment::query()->create([
            'usuario_id' => $teacher->id,
            'paralelo_id' => $parallel->id,
            'activo' => true,
        ]);

        return $parallel;
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
            ->where('plantilla_id', $syllabus->plantilla_id)
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

    private function openedSyllabus(): Syllabus
    {
        [$template, $source] = $this->publishedConfiguration();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
            'source_ids' => [$source->id],
        ])->assertSuccessful();
        $convocation = Convocation::query()->firstOrFail();

        return Syllabus::query()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validFieldPayload(FieldDefinition $field, int $lockVersion): array
    {
        $optionValues = collect($field->opciones ?? [])->map(fn (mixed $option): string => is_array($option) ? (string) ($option['value'] ?? 'opcion') : (string) $option)->filter()->values();

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
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla relevo']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.sources.store'), ['nombre' => 'Fuente relevo', 'description' => 'Documento de apoyo del periodo.']);
        $source = AcademicSource::query()->firstOrFail();
        $this->actingAsCoordinator()->putJson(route('api.v1.coordination.sources.content.update', $source), ['content' => "## Perfil base\n\nEvidencia académica autorizada."]);

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
