<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
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
use Tests\TestCase;

class ParallelTeacherChangeTest extends TestCase
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

    public function test_pausing_and_resuming_the_convocation_sends_no_notices(): void
    {
        $this->openedSyllabus();
        $convocation = Convocation::query()->with('career')->firstOrFail();

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'pausar']))
            ->assertSuccessful();
        // I-186: pausar y reanudar la convocatoria ya no generan avisos; se ven en el panel.

        $this->actingAsCoordinator()
            ->postJson(route('api.v1.coordination.convocations.transition', [$convocation, 'reanudar']))
            ->assertSuccessful();
        $this->assertNotNotified($this->teacher->id, 'convocatoria.pausada');
        $this->assertNotNotified($this->teacher->id, 'convocatoria.reanudada');
        $this->assertNotNotified($this->coordinator->id, 'convocatoria.pausada');
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
        $template = $this->publishedConfiguration();
        $this->actingAsCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $convocation = Convocation::query()->firstOrFail();

        return Syllabus::query()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validFieldPayload(FieldDefinition $field, int $lockVersion): array
    {
        $optionValues = collect($field->opciones ?? [])->map(fn (mixed $option): string => is_array($option) ? (string) ($option['value'] ?? 'opcion') : (string) $option)->filter()->values();

        return match (true) {
            $field->tipo === 'texto_libre' => ['version_bloqueo' => $lockVersion, 'value' => $this->freeTextValue("Contenido académico {$field->clave}")],
            $field->tipo === 'booleano' => ['version_bloqueo' => $lockVersion, 'value' => true],
            $field->tipo === 'numero' => ['version_bloqueo' => $lockVersion, 'value' => 1],
            $field->tipo === 'fecha' => ['version_bloqueo' => $lockVersion, 'value' => now()->toDateString()],
            $field->tipo === 'seleccion_unica' => ['version_bloqueo' => $lockVersion, 'value' => $optionValues->first()],
            $field->tipo === 'seleccion_multiple' => ['version_bloqueo' => $lockVersion, 'value' => [$optionValues->first()]],
            default => ['version_bloqueo' => $lockVersion, 'value' => "Contenido académico {$field->clave}"],
        };
    }

    private function publishedConfiguration(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla cambio docente']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();

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
