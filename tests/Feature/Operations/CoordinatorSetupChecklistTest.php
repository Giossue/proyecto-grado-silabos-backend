<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Application\SyllabusTemplateProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CoordinatorSetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_steps_follow_their_dependencies_teachers_before_the_parallels_that_need_them(): void
    {
        $keys = collect($this->dashboard()->json('setup.steps'))->pluck('key')->all();

        $this->assertSame(['curriculum', 'scheduled_subjects', 'teachers', 'parallels', 'career_project', 'convocation'], $keys);
    }

    public function test_the_career_project_must_be_complete_before_opening_the_convocation(): void
    {
        app(SyllabusTemplateProvisioner::class)->createWithOfficialFormat();
        $this->careerProjectFields()->firstWhere('clave', 'descripcion')->update(['contenido' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'response', 'attrs' => ['kind' => 'una', 'options' => [], 'value' => null]]]]]]]);
        $careerId = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail()->roleAssignments()->firstOrFail()->carrera_id;
        $subjects = Subject::query()->where('activo', true)->where('genera_silabo', true)->where('carrera_id', $careerId)->count();
        $step = collect($this->dashboard()->json('setup.steps'))->firstWhere('key', 'career_project');

        $this->assertIsArray($step);
        $this->assertSame('Completar el proyecto de carrera', $step['label']);
        $this->assertFalse($step['done']);
        $this->assertSame("Falta en {$subjects} de {$subjects} materias.", $step['hint']);
    }

    private function dashboard(): TestResponse
    {
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();

        return $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $coordinator->roleAssignments()->firstOrFail()->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson(route('api.v1.dashboard.show'))
            ->assertOk();
    }
}
