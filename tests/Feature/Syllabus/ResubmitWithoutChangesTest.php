<?php

namespace Tests\Feature\Syllabus;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\Support\CreatesSyllabusProcess;
use Tests\Support\SignsStatements;
use Tests\TestCase;

class ResubmitWithoutChangesTest extends TestCase
{
    use ConfiguresFacultyLogos, CreatesSyllabusProcess, RefreshDatabase, SignsStatements;

    private User $teacher;

    private RoleAssignment $teacherContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    public function test_the_teacher_payload_says_whether_anything_changed_since_the_correction_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        Queue::fake([GenerateSyllabusExportJob::class]);
        $this->teacher = User::query()->where('correo_electronico', 'docente@silabos.test')->firstOrFail();
        $this->teacherContext = $this->teacher->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();

        $syllabus = $this->validDraft();
        $this->asTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()->assertJsonPath('syllabus.unchanged_since_correction', false);

        $this->asTeacher()->postJson(route('api.v1.teacher.syllabi.submit', $syllabus), [
            'signature' => $this->teacherSignature($syllabus, $this->teacher),
            'version_bloqueo' => $syllabus->version_bloqueo,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();
        $revision = SyllabusRevision::query()->latest('enviado_en')->firstOrFail();
        $librarian = User::query()->where('correo_electronico', 'bibliotecario@silabos.test')->firstOrFail();
        $this->actingAs($librarian)->withSession(['active_role_assignment_id' => $librarian->roleAssignments()->firstOrFail()->id])
            ->postJson(route('api.v1.library.reviews.verify', $revision), ['signature' => $this->revisionSignature($revision, 'biblioteca', $librarian)])->assertCreated();
        $this->asCoordinator()->postJson(route('api.v1.coordination.reviews.correction.store', $revision), [
            'justification' => 'Se requiere precisión académica antes de aprobar la revisión.',
        ])->assertSuccessful();

        $this->asTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()->assertJsonPath('syllabus.unchanged_since_correction', true);

        $field = FieldDefinition::query()->where('clave', 'tecnicas_ensenanza')->firstOrFail();
        $this->asTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
            'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
            'value' => $this->answeredValue($field, 'Técnicas ajustadas tras la corrección'),
        ])->assertOk();

        $this->asTeacher()->getJson(route('api.v1.teacher.syllabi.show', $syllabus))
            ->assertOk()->assertJsonPath('syllabus.unchanged_since_correction', false);
    }

    private function validDraft(): Syllabus
    {
        $this->actingAs(User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail())
            ->withSession(['active_role_assignment_id' => User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail()->roleAssignments()->firstOrFail()->id])
            ->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla reenvío']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->postJson(route('api.v1.admin.template.format', $template))->assertSuccessful();
        $this->fillSubjectProfiles();
        $this->asCoordinator()->postJson(route('api.v1.coordination.convocations.store'), [
            'process_id' => $this->openSyllabusProcess($template->id, now()->subDay()->toIso8601String(), now()->addMonth()->toIso8601String())->id,
        ])->assertSuccessful();
        $syllabus = Syllabus::query()->firstOrFail();
        $this->asTeacher()->postJson(route('api.v1.teacher.syllabi.start', $syllabus))->assertSuccessful();
        foreach ($this->teacherFields() as $field) {
            $this->asTeacher()->patchJson(route('api.v1.teacher.syllabi.fields.update', [$syllabus, $field]), [
                'version_bloqueo' => $syllabus->fresh()->version_bloqueo,
                'value' => $this->answeredValue($field, "Contenido académico {$field->clave}"),
            ])->assertOk();
        }

        return $syllabus->fresh();
    }

    private function asTeacher(): static
    {
        return $this->actingAs($this->teacher)->withSession(['active_role_assignment_id' => $this->teacherContext->id]);
    }

    private function asCoordinator(): static
    {
        return $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);
    }
}
