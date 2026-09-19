<?php

namespace Tests\Feature\AiAssistance;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareerAiConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:5173']]);
    }

    public function test_coordinator_override_is_effective_only_while_template_revision_matches(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $role = $coordinator->roleAssignments()->firstOrFail();
        [$field, $section] = $this->field(true);
        $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $role->id])
            ->withHeader('Origin', 'http://localhost:5173');

        $this->putJson(route('api.v1.coordination.ai-configuration.update'), [
            'preferences' => [['field_id' => $field->id, 'enabled' => false]],
        ])->assertOk();
        $this->getJson(route('api.v1.coordination.ai-configuration.show'))
            ->assertOk()
            ->assertJsonFragment(['id' => $field->id, 'override' => false, 'effective' => false]);

        $section->update(['titulo' => 'IA actualizada']);

        $this->getJson(route('api.v1.coordination.ai-configuration.show'))
            ->assertOk()
            ->assertJsonFragment(['id' => $field->id, 'override' => null, 'effective' => true]);
    }

    public function test_coordinator_cannot_override_a_field_locked_by_administration(): void
    {
        $this->seed(DatabaseSeeder::class);
        $coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $role = $coordinator->roleAssignments()->firstOrFail();
        [$field] = $this->field(false);

        $this->actingAs($coordinator)
            ->withSession(['active_role_assignment_id' => $role->id])
            ->withHeader('Origin', 'http://localhost:5173')
            ->putJson(route('api.v1.coordination.ai-configuration.update'), [
                'preferences' => [['field_id' => $field->id, 'enabled' => ! $field->ia_habilitada]],
            ])->assertUnprocessable();

        $this->assertDatabaseCount('preferencias_ia_carrera', 0);
    }

    /** @return array{FieldDefinition, TemplateSection} */
    private function field(bool $configurable): array
    {
        $template = SyllabusTemplate::query()->create(['nombre' => 'Plantilla IA', 'activo' => true]);
        $section = TemplateSection::query()->create([
            'plantilla_id' => $template->id, 'clave' => 'ia_test', 'titulo' => 'IA', 'posicion' => 999,
        ]);
        $block = TemplateBlock::query()->create([
            'plantilla_id' => $template->id, 'seccion_plantilla_id' => $section->id,
            'clave' => 'ia_test', 'tipo' => 'campos', 'titulo' => 'IA', 'posicion' => 1,
        ]);
        $field = FieldDefinition::query()->create([
            'plantilla_id' => $template->id, 'bloque_plantilla_id' => $block->id,
            'clave' => 'ia_test', 'etiqueta' => 'Campo IA', 'tipo' => 'texto_largo',
            'heredado' => false, 'editable_docente' => true,
            'ia_habilitada' => true, 'ia_coordinacion_configurable' => $configurable, 'posicion' => 1,
        ]);

        return [$field, $section];
    }
}
