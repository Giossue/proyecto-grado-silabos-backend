<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesSyllabusProcess;
use Tests\TestCase;

class TemplateStructureApiTest extends TestCase
{
    use CreatesSyllabusProcess, RefreshDatabase;

    private SyllabusTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.store'), ['nombre' => 'Plantilla MVP'])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
    }

    private function actingAsRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $assignment = $user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);
    }

    private function section(string $key = 'datos', string $title = 'Datos'): string
    {
        return (string) $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), [
            'key' => $key, 'title' => $title,
        ])->assertCreated()->json('section_id');
    }

    /** @param array<string, mixed> $extra */
    private function field(string $section, string $key, string $type, array $extra = []): TestResponse
    {
        return $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $section]), [
            'key' => $key, 'label' => 'Campo '.$key, 'type' => $type, ...$extra,
        ]);
    }

    public function test_creates_a_field_of_each_type_with_its_config(): void
    {
        $section = $this->section();

        $this->field($section, 'resumen', 'texto', ['help' => 'Escriba un resumen.'])->assertCreated()
            ->assertJsonPath('field.type', 'texto')->assertJsonPath('field.help', 'Escriba un resumen.');
        $this->field($section, 'perfil', 'ficha', ['config' => ['adjustable' => true]])->assertCreated()
            ->assertJsonPath('field.config.adjustable', true);
        $this->field($section, 'semanas', 'tabla', ['config' => [
            'columns' => [['key' => 'tema', 'label' => 'Tema']],
            'rows' => [['key' => 's1', 'label' => 'Semana 1'], ['key' => 's2', 'label' => 'Semana 2']],
        ]])->assertCreated()->assertJsonPath('field.type', 'tabla');
        $this->field($section, 'identificacion', 'automatico', ['config' => ['source' => 'identification_card']])
            ->assertCreated()->assertJsonPath('field.config.source', 'identification_card');

        $fields = FieldDefinition::query()->where('plantilla_id', $this->template->id)->get()->keyBy('clave');
        $this->assertTrue($fields['resumen']->editable_docente);
        $this->assertTrue($fields['perfil']->heredado);
        $this->assertSame(FieldDefinition::ORIGIN_PROFILE, $fields['perfil']->origen_maestro);
        $this->assertTrue($fields['perfil']->editable_docente);
        $this->assertSame(['s1', 's2'], array_column($fields['semanas']->typeConfig()['rows'], 'key'));
        $this->assertFalse($fields['identificacion']->editable_docente);
        $this->assertSame('identification_card', $fields['identificacion']->origen_maestro);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.campo_creado']);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.seccion_creada']);

        $this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk()
            ->assertJsonPath('template.sections.0.blocks.2.fields.0.type', 'tabla')
            ->assertJsonPath('template.sections.0.blocks.1.fields.0.config.adjustable', true);
    }

    public function test_rejects_invalid_type_and_config(): void
    {
        $section = $this->section();

        $this->field($section, 'a', 'seleccion')->assertJsonValidationErrors('type');
        $this->field($section, 'b', 'tabla', ['config' => ['columns' => [], 'rows' => []]])
            ->assertJsonValidationErrors(['config.columns', 'config.rows']);
        $this->field($section, 'c', 'automatico', ['config' => ['source' => 'inventada']])
            ->assertJsonValidationErrors('config.source');
        $this->field($section, 'd', 'automatico')->assertJsonValidationErrors('config.source');
        $this->field($section, 'e', 'texto')->assertCreated();
        $this->field($section, 'e', 'texto')->assertJsonValidationErrors('key');
        $this->assertSame(1, FieldDefinition::query()->where('plantilla_id', $this->template->id)->count());
    }

    public function test_updates_a_field_without_changing_its_type_or_key(): void
    {
        $section = $this->section();
        $id = (string) $this->field($section, 'perfil', 'ficha')->assertCreated()->json('field.id');
        $url = route('api.v1.admin.template.structure.fields.update', [$this->template, $id]);

        $this->patchJson($url, ['label' => 'Perfil nuevo', 'help' => 'Ayuda', 'config' => ['adjustable' => true]])
            ->assertOk()->assertJsonPath('field.label', 'Perfil nuevo')->assertJsonPath('field.config.adjustable', true);
        $field = FieldDefinition::query()->findOrFail($id);
        $this->assertTrue($field->editable_docente);
        $this->assertSame('Perfil nuevo', $field->block->titulo);

        $this->patchJson($url, ['type' => 'texto'])->assertJsonValidationErrors('type');
        $this->patchJson($url, ['config' => ['adjustable' => 'quizas']])->assertJsonValidationErrors('config.adjustable');
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.campo_actualizado']);
    }

    public function test_reorders_deletes_and_renames_sections_and_fields(): void
    {
        $first = $this->section('uno', 'Uno');
        $second = $this->section('dos', 'Dos');
        $a = (string) $this->field($first, 'a', 'texto')->json('field.id');
        $b = (string) $this->field($first, 'b', 'texto', ['position' => 1])->json('field.id');

        $this->template->refresh();
        $this->assertSame([$b, $a], $this->template->section($first)->blocks()->map(fn ($block) => $block->fields->first()->id)->all());

        $this->patchJson(route('api.v1.admin.template.structure.fields.reorder', [$this->template, $first]), ['field_ids' => [$a, $b]])->assertOk();
        $this->assertSame([$a, $b], $this->template->fresh()->section($first)->blocks()->map(fn ($block) => $block->fields->first()->id)->all());
        $this->patchJson(route('api.v1.admin.template.structure.fields.reorder', [$this->template, $first]), ['field_ids' => [$a]])
            ->assertJsonValidationErrors('blocks');

        $this->patchJson(route('api.v1.admin.template.structure.sections.reorder', $this->template), ['section_ids' => [$second, $first]])->assertOk();
        $this->assertSame([$second, $first], $this->template->fresh()->sections()->pluck('id')->all());
        $this->patchJson(route('api.v1.admin.template.structure.sections.update', [$this->template, $first]), ['title' => 'Uno renombrada'])->assertOk();
        $this->assertSame('Uno renombrada', $this->template->fresh()->section($first)->titulo);

        $this->deleteJson(route('api.v1.admin.template.structure.fields.destroy', [$this->template, $a]))->assertOk();
        $this->assertNull(FieldDefinition::query()->find($a));
        $this->deleteJson(route('api.v1.admin.template.structure.sections.destroy', [$this->template, $second]))->assertOk();
        $this->assertCount(1, $this->template->fresh()->sections());
    }

    public function test_only_administration_manages_the_structure(): void
    {
        $section = $this->section();
        $id = (string) $this->field($section, 'a', 'texto')->json('field.id');

        foreach (['coordinador@silabos.test', 'docente@silabos.test'] as $email) {
            $this->actingAsRole($email);
            $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'x', 'title' => 'X'])->assertForbidden();
            $this->field($section, 'z', 'texto')->assertForbidden();
            $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$this->template, $id]), ['label' => 'No'])->assertForbidden();
            $this->deleteJson(route('api.v1.admin.template.structure.fields.destroy', [$this->template, $id]))->assertForbidden();
            $this->patchJson(route('api.v1.admin.template.structure.sections.reorder', $this->template), ['section_ids' => [$section]])->assertForbidden();
            $this->deleteJson(route('api.v1.admin.template.structure.sections.destroy', [$this->template, $section]))->assertForbidden();
        }
    }

    public function test_an_open_process_blocks_every_structure_change(): void
    {
        $section = $this->section();
        $id = (string) $this->field($section, 'a', 'texto')->json('field.id');
        $this->openSyllabusProcess($this->template->id);

        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'x', 'title' => 'X'])->assertJsonValidationErrors('process');
        $this->field($section, 'z', 'texto')->assertJsonValidationErrors('process');
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$this->template, $id]), ['label' => 'No'])->assertJsonValidationErrors('process');
        $this->deleteJson(route('api.v1.admin.template.structure.fields.destroy', [$this->template, $id]))->assertJsonValidationErrors('process');
        $this->deleteJson(route('api.v1.admin.template.structure.sections.destroy', [$this->template, $section]))->assertJsonValidationErrors('process');
        $this->assertNotNull(FieldDefinition::query()->find($id));
    }
}
