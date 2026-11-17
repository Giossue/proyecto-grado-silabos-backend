<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemplateVersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private SyllabusTemplate $template;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->actingAsRole('admin@silabos.test');
        $this->postJson(route('api.v1.admin.template.store'), ['official' => true])->assertCreated();
        $this->template = SyllabusTemplate::query()->firstOrFail();
    }

    private function actingAsRole(string $email): User
    {
        $user = User::query()->where('correo_electronico', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function exported(): array
    {
        return $this->getJson(route('api.v1.admin.template.json.export', $this->template))->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function renamedFirstSection(string $title): array
    {
        $json = $this->exported();
        $json['sections'][0]['title'] = $title;

        return $json;
    }

    public function test_the_history_is_never_empty_and_starts_with_the_current_template_by_the_system(): void
    {
        $response = $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->assertOk();

        $response->assertJsonCount(1, 'versions')
            ->assertJsonPath('versions.0.number', 1)
            ->assertJsonPath('versions.0.saved_by', 'Sistema')
            ->assertJsonPath('versions.0.current', true)
            ->assertJsonPath('versions.0.sections', count($this->exported()['sections']));
        $this->assertGreaterThan(0, $response->json('versions.0.fields'));
        $this->assertNotNull($response->json('versions.0.saved_at'));
        $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->assertJsonCount(1, 'versions');
    }

    public function test_each_save_stores_a_version_with_who_and_when(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->renamedFirstSection('Datos generales'))->assertOk();

        $this->assertDatabaseCount('versiones_plantilla', 2);
        $versions = $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->assertOk();
        $versions->assertJsonCount(2, 'versions')
            ->assertJsonPath('versions.0.number', 2)
            ->assertJsonPath('versions.0.saved_by', $this->admin->nombre)
            ->assertJsonPath('versions.0.current', true)
            ->assertJsonPath('versions.1.number', 1)
            ->assertJsonPath('versions.1.saved_by', 'Sistema')
            ->assertJsonPath('versions.1.current', false);

        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->renamedFirstSection('Otra vez'))->assertOk();
        $this->assertDatabaseCount('versiones_plantilla', 3);
    }

    public function test_one_version_returns_its_json_and_a_draft_for_the_editor(): void
    {
        $before = $this->exported();
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->renamedFirstSection('Datos generales'))->assertOk();
        $first = $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->json('versions.1.id');

        $response = $this->getJson(route('api.v1.admin.template.versions.show', [$this->template, $first]))->assertOk();

        $response->assertJsonPath('number', 1)
            ->assertJsonPath('json.sections.0.title', $before['sections'][0]['title'])
            ->assertJsonPath('draft.sections.0.title', $before['sections'][0]['title'])
            ->assertJsonPath('draft.sections.0.fields.0.content.type', 'doc')
            ->assertJsonPath('draft.title', $before['document']['title']['text']);
        $this->assertEquals($before, $response->json('json'));
    }

    public function test_the_comparison_lists_what_changed_since_the_version_in_short_phrases(): void
    {
        $json = $this->exported();
        $json['sections'][] = ['key' => 'anexos', 'title' => 'Anexos', 'fields' => []];
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $json)->assertOk();
        $first = $json['sections'][0];
        array_pop($json['sections']);
        $json['sections'][0]['title'] = 'Datos generales';
        $json['sections'][] = ['key' => 'apendice', 'title' => 'Apéndice', 'fields' => []];
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $json)->assertOk();
        $versions = $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->json('versions');

        $changes = $this->getJson(route('api.v1.admin.template.versions.comparison', [$this->template, $versions[1]['id']]))->assertOk()->json('changes');

        $this->assertSame([
            'Sección agregada: «Apéndice»',
            'Sección quitada: «Anexos»',
            "Sección renombrada: «{$first['title']}» → «Datos generales»",
        ], $changes);
        $this->getJson(route('api.v1.admin.template.versions.comparison', [$this->template, $versions[0]['id']]))->assertOk()->assertExactJson(['changes' => []]);
    }

    public function test_only_the_administrator_sees_the_history(): void
    {
        $id = $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->json('versions.0.id');
        $this->actingAsRole('coordinador@silabos.test');

        $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->assertForbidden();
        $this->getJson(route('api.v1.admin.template.versions.show', [$this->template, $id]))->assertForbidden();
        $this->getJson(route('api.v1.admin.template.versions.comparison', [$this->template, $id]))->assertForbidden();
    }

    public function test_a_version_of_another_id_is_not_found(): void
    {
        $this->getJson(route('api.v1.admin.template.versions.show', [$this->template, '019a0000-0000-7000-8000-000000000000']))->assertNotFound();
    }

    public function test_a_stored_version_cannot_be_changed(): void
    {
        $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->assertOk();

        $this->expectException(QueryException::class);
        DB::table('versiones_plantilla')->update(['numero_version_plantilla' => 9]);
    }

    public function test_saving_still_replaces_the_template_as_before(): void
    {
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), $this->renamedFirstSection('Datos generales'))
            ->assertOk()->assertExactJson(['message' => 'Plantilla importada desde el JSON.']);

        $this->assertSame('Datos generales', $this->exported()['sections'][0]['title']);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'plantilla.importada']);
    }

    public function test_a_rejected_save_stores_no_version(): void
    {
        $this->getJson(route('api.v1.admin.template.versions.index', $this->template))->assertOk();
        $this->putJson(route('api.v1.admin.template.json.import', $this->template), ['version' => 2])->assertUnprocessable();

        $this->assertDatabaseCount('versiones_plantilla', 1);
    }
}
