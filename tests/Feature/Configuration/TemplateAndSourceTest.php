<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemplateAndSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private RoleAssignment $administratorContext;

    private User $coordinator;

    private RoleAssignment $coordinatorContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->administrator = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->administratorContext = $this->administrator->roleAssignments()->firstOrFail();
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->coordinatorContext = $this->coordinator->roleAssignments()->firstOrFail();
    }

    public function test_administrator_creates_the_template_directly_with_the_official_format(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), ['official' => true])
            ->assertCreated()
            ->assertJsonPath('message', 'Plantilla creada con el formato oficial.');

        $template = SyllabusTemplate::query()->firstOrFail();
        $this->assertGreaterThan(0, $template->sections()->count());
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.creada', 'recurso_id' => $template->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.formateada', 'recurso_id' => $template->id]);
    }

    public function test_administrator_can_only_create_one_institutional_template(): void
    {
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla institucional'])
            ->assertSuccessful();

        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Otra plantilla'])
            ->assertJsonValidationErrors('template');

        $this->assertSame(1, SyllabusTemplate::query()->count());
    }

    public function test_database_rejects_a_second_template(): void
    {
        SyllabusTemplate::query()->create(['nombre' => 'Plantilla oficial', 'activo' => true]);

        $this->expectException(QueryException::class);
        SyllabusTemplate::query()->create(['nombre' => 'Plantilla duplicada', 'activo' => true]);
    }

    public function test_database_rejects_a_field_for_a_block_absent_from_the_template_document(): void
    {
        $template = $this->createTemplate();

        $this->expectException(QueryException::class);
        FieldDefinition::query()->create([
            'plantilla_id' => $template->id,
            'campo_plantilla_id' => (string) Str::uuid(),
            'clave' => 'campo_huerfano',
            'etiqueta' => 'Campo huérfano',
            'tipo' => 'texto_largo',
            'heredado' => false,
            'editable_docente' => true,
            'posicion' => 1,
        ]);
    }

    public function test_non_administrator_cannot_manage_templates(): void
    {
        $template = $this->createTemplate();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.admin.template.index'))
            ->assertForbidden();

        $this->actingAsCoordinator()
            ->getJson(route('api.v1.admin.template.show', $template))
            ->assertForbidden();
    }

    public function test_source_management_endpoints_are_retired_for_every_role(): void
    {
        foreach ([$this->administrator, $this->coordinator] as $user) {
            $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
            $this->getJson('/api/v1/coordination/sources')->assertNotFound();
            $this->postJson('/api/v1/coordination/sources', ['nombre' => 'Retirada'])->assertNotFound();
            $id = (string) Str::uuid();
            foreach (['get', 'patch', 'put', 'delete'] as $method) {
                $this->{$method.'Json'}('/api/v1/coordination/sources/'.$id)->assertNotFound();
            }
            $this->putJson('/api/v1/coordination/sources/'.$id.'/content', ['content' => 'Retirada'])->assertNotFound();
            $this->postJson('/api/v1/coordination/sources/'.$id.'/pdf')->assertNotFound();
        }
    }

    private function createTemplate(): SyllabusTemplate
    {
        $this->actingAsAdministrator()->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla verificable']);
        $template = SyllabusTemplate::query()->firstOrFail();
        $this->actingAsAdministrator()
            ->postJson(route('api.v1.admin.template.format', $template))
            ->assertSuccessful();

        return $template->fresh();
    }

    private function actingAsAdministrator(): static
    {
        $this->actingAs($this->administrator)
            ->withSession(['active_role_assignment_id' => $this->administratorContext->id]);

        return $this;
    }

    private function actingAsCoordinator(): static
    {
        $this->actingAs($this->coordinator)
            ->withSession(['active_role_assignment_id' => $this->coordinatorContext->id]);

        return $this;
    }
}
