<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->postJson(route('api.v1.admin.template.store'))->assertCreated();
        $this->template = SyllabusTemplate::institutional();
    }

    private function actingAsRole(string $email): void
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
    }

    private function section(string $key = 'datos'): string
    {
        return $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => $key, 'title' => 'Datos'])->assertCreated()->json('section_id');
    }

    public function test_creates_documents_for_each_responsible_without_types(): void
    {
        $section = $this->section();
        foreach (FieldDefinition::RESPONSIBLES as $responsible) {
            $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $section]), [
                'key' => $responsible, 'label' => 'Contenido', 'quien_llena' => $responsible, 'content' => FreeText::fromText('Contenido inicial'),
            ])->assertCreated()->assertJsonPath('field.quien_llena', $responsible)->assertJsonMissingPath('field.type');
        }
        $this->getJson(route('api.v1.admin.template.show', $this->template))->assertOk()
            ->assertJsonCount(3, 'template.sections.0.fields')->assertJsonPath('template.sections.0.fields.0.content.type', 'doc');
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.campo_creado']);
    }

    public function test_rejects_old_types_invalid_responsibles_and_unknown_variables(): void
    {
        $section = $this->section();
        $url = route('api.v1.admin.template.structure.fields.store', [$this->template, $section]);
        $data = ['key' => 'dato', 'label' => '', 'quien_llena' => 'docente', 'content' => FieldDocument::empty()];
        $this->postJson($url, [...$data, 'type' => 'texto'])->assertJsonValidationErrors('type');
        $this->postJson($url, [...$data, 'config' => ['rows' => []]])->assertJsonValidationErrors('config');
        $this->postJson($url, [...$data, 'quien_llena' => 'otro'])->assertJsonValidationErrors('quien_llena');
        $this->postJson($url, [...$data, 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'inexistente']]]]]]])->assertJsonValidationErrors('content');
        $this->assertSame(0, $this->template->fields()->count());
    }

    public function test_edits_content_responsible_and_orientation(): void
    {
        $section = $this->section();
        $field = $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $section]), [
            'key' => 'dato', 'label' => '', 'quien_llena' => 'docente', 'content' => FieldDocument::empty(),
        ])->assertCreated()->json('field.id');
        $this->patchJson(route('api.v1.admin.template.structure.fields.update', [$this->template, $field]), [
            'quien_llena' => 'coordinacion', 'content' => FreeText::fromText('Desde la ficha'),
        ])->assertOk()->assertJsonPath('field.quien_llena', 'coordinacion');
        $this->patchJson(route('api.v1.admin.template.structure.sections.update', [$this->template, $section]), [
            'title' => 'Planificación', 'orientation' => 'landscape',
        ])->assertOk();
        $this->getJson(route('api.v1.admin.template.show', $this->template))->assertJsonPath('template.sections.0.orientation', 'landscape');
    }

    public function test_reorders_and_deletes_fields_and_sections(): void
    {
        $section = $this->section();
        $ids = [];
        foreach (['primero', 'segundo'] as $key) {
            $ids[] = $this->postJson(route('api.v1.admin.template.structure.fields.store', [$this->template, $section]), [
                'key' => $key, 'label' => $key, 'quien_llena' => 'docente', 'content' => FieldDocument::empty(),
            ])->assertCreated()->json('field.id');
        }
        $this->patchJson(route('api.v1.admin.template.structure.fields.reorder', [$this->template, $section]), ['field_ids' => array_reverse($ids)])->assertOk();
        $this->getJson(route('api.v1.admin.template.show', $this->template))->assertJsonPath('template.sections.0.fields.0.id', $ids[1]);
        $this->deleteJson(route('api.v1.admin.template.structure.fields.destroy', [$this->template, $ids[0]]))->assertOk();
        $this->deleteJson(route('api.v1.admin.template.structure.sections.destroy', [$this->template, $section]))->assertOk();
        $this->assertCount(0, $this->template->fresh()->sections);
    }

    public function test_denies_non_administrators_and_blocks_changes_with_open_process(): void
    {
        $this->actingAsRole('docente@silabos.test');
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'datos', 'title' => 'Datos'])->assertForbidden();
        $this->actingAsRole('admin@silabos.test');
        $this->openSyllabusProcess($this->template->id);
        $this->postJson(route('api.v1.admin.template.structure.sections.store', $this->template), ['key' => 'datos', 'title' => 'Datos'])->assertUnprocessable();
    }

    public function test_title_logo_and_old_field_apis_are_removed(): void
    {
        foreach (['title', 'appearance', 'fields', 'blocks/ejemplo/document'] as $path) {
            $this->patchJson('/api/v1/admin/template/'.$this->template->id.'/'.$path, [])->assertNotFound();
        }
        $this->postJson('/api/v1/admin/template/logo', [])->assertStatus(405);
    }
}
