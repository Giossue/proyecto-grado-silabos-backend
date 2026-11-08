<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicFormRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_faculty_is_created_and_edited_without_a_logo(): void
    {
        $this->actingAsRole('admin@silabos.test')
            ->postJson(route('api.v1.admin.academic.store', 'facultad'), [
                'nombre' => 'Facultad sin logo', 'campus' => CampusLocation::Matriz->value,
            ])
            ->assertSuccessful();

        $faculty = Faculty::query()->where('nombre_facultad', 'Facultad sin logo')->firstOrFail();
        $this->assertNull($faculty->logo_objeto_id);

        $this->actingAsRole('admin@silabos.test')
            ->patchJson(route('api.v1.admin.academic.update', ['facultad', $faculty->id]), [
                'nombre' => 'Facultad sin logo renombrada', 'campus' => CampusLocation::Matriz->value,
            ])
            ->assertSuccessful();
        $this->assertSame('Facultad sin logo renombrada', $faculty->refresh()->nombre);
    }

    public function test_a_repeated_code_says_which_record_already_uses_it(): void
    {
        $career = Career::query()->firstOrFail();
        $this->actingAsRole('admin@silabos.test')
            ->postJson(route('api.v1.admin.academic.store', 'carrera'), [
                'faculty_id' => $career->facultad_id, 'modality' => StudyModality::cases()[0]->value,
                'code' => $career->codigo_carrera, 'nombre' => 'Carrera repetida', 'cycle_count' => 8,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['Ya existe una carrera con ese código.']);

        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $subject = Subject::query()->where('carrera_id', $coordinator->roleAssignments()->firstOrFail()->carrera_id)->firstOrFail();
        $this->actingAsRole('coordinador@silabos.test')
            ->postJson(route('api.v1.coordination.academic.store', 'asignatura'), [
                'code' => $subject->codigo_asignatura, 'nombre' => 'Materia repetida', 'cycle' => 1,
                'organization_unit' => 'profesional', 'horas_ac' => 48, 'horas_pae' => 32, 'horas_aa' => 64, 'creditos' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['Ya existe una materia con ese código.']);
    }

    private function actingAsRole(string $email): static
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);

        return $this;
    }
}
