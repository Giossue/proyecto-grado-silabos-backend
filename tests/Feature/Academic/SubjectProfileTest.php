<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectProfile;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_coordinator_and_administrator_can_read_and_save_the_subject_profile(): void
    {
        $subject = Subject::query()->firstOrFail();
        $this->asRole('coordinador@silabos.test');
        $this->getJson(route('api.v1.academic.subjects.profile.show', $subject))
            ->assertOk()->assertJsonPath('profile.descripcion', 'Estudio de arquitecturas de software.');

        $payload = $this->payload();
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), $payload)
            ->assertOk()->assertJsonPath('profile.objetivo_general', $payload['objetivo_general']);
        $this->assertDatabaseCount('fichas_asignatura', 1);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.ficha_asignatura.guardada']);

        $this->asRole('admin@silabos.test');
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), $payload)
            ->assertOk();
        $this->assertDatabaseCount('fichas_asignatura', 1);
    }

    public function test_another_career_and_teacher_cannot_access_the_profile(): void
    {
        $subject = Subject::query()->firstOrFail();
        $career = Career::query()->firstOrFail()->replicate();
        $career->codigo_carrera = 'OTRA';
        $career->save();
        $foreign = $subject->replicate();
        $foreign->carrera_id = $career->id;
        $foreign->save();

        $this->asRole('coordinador@silabos.test');
        $this->getJson(route('api.v1.academic.subjects.profile.show', $foreign))->assertForbidden();
        $this->putJson(route('api.v1.academic.subjects.profile.update', $foreign), $this->payload())->assertForbidden();

        $this->asRole('docente@silabos.test');
        $this->getJson(route('api.v1.academic.subjects.profile.show', $subject))->assertForbidden();
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), $this->payload())->assertForbidden();
    }

    public function test_one_profile_per_subject_is_enforced_by_postgresql(): void
    {
        $subject = Subject::query()->firstOrFail();
        $this->expectException(QueryException::class);
        SubjectProfile::query()->create(['asignatura_id' => $subject->id]);
    }

    public function test_profile_lists_reject_empty_items_and_missing_skill_names(): void
    {
        $subject = Subject::query()->firstOrFail();
        $this->asRole('coordinador@silabos.test');
        $payload = $this->payload();
        $payload['objetivos_especificos'] = [''];
        $payload['habilidades_blandas'] = [['nombre' => '', 'definicion' => 'Colabora']];
        $this->putJson(route('api.v1.academic.subjects.profile.update', $subject), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['objetivos_especificos.0', 'habilidades_blandas.0.nombre']);
    }

    private function asRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $role = RoleAssignment::query()->where('usuario_id', $user->id)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $role->id]);
    }

    private function payload(): array
    {
        return [
            'descripcion' => 'Descripción de prueba',
            'objetivo_general' => 'Objetivo de prueba',
            'objetivos_especificos' => ['Analizar datos'],
            'resultados_aprendizaje' => ['Diseña sistemas'],
            'habilidades_blandas' => [['nombre' => 'Comunicación', 'definicion' => 'Expresa ideas']],
            'bibliografia_basica' => ['Referencia básica'],
            'bibliografia_complementaria' => [],
            'herramientas_tic' => ['Editor'],
            'herramientas_ia' => [['herramienta' => 'Asistente', 'caracteristica' => 'Sugiere ideas']],
            'relacion_perfil_egreso' => 'Aporta al diseño',
        ];
    }
}
