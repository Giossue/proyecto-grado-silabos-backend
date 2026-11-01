<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\TestCase;

class FacultyCatalogLibrariesTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;

    /** @var array<string, array{User, RoleAssignment}> */
    private array $people = [];

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        foreach (['admin' => 'admin', 'teacher' => 'docente', 'librarian' => 'bibliotecario'] as $key => $mail) {
            $user = User::query()->where('correo_electronico', "{$mail}@silabos.test")->firstOrFail();
            $this->people[$key] = [$user, $user->roleAssignments()->firstOrFail()];
        }
        $this->configureFacultyLogos();
        $this->faculty = Faculty::query()->firstOrFail();
        config(['catalog.driver' => 'koha', 'catalog.koha.base_url' => 'http://koha.test']);
    }

    public function test_the_administrator_picks_from_the_libraries_koha_publishes(): void
    {
        Http::fake(['*/api/v1/public/libraries' => Http::response([
            ['library_id' => 'BHTM', 'name' => 'SAN MIGUEL'],
            ['library_id' => 'BFCCA', 'name' => 'LAGUACOTO'],
            ['library_id' => 'BGEN', 'name' => 'MATRIZ'],
            ['library_id' => 'MAL CODIGO!', 'name' => 'Se ignora'],
        ])]);

        $this->as('admin')->getJson(route('api.v1.admin.library-catalog.libraries'))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => 'BFCCA', 'name' => 'LAGUACOTO'],
                ['id' => 'BGEN', 'name' => 'MATRIZ'],
                ['id' => 'BHTM', 'name' => 'SAN MIGUEL'],
            ]]);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept', 'application/json'));
    }

    public function test_the_list_of_libraries_is_only_for_administration_and_reports_an_unavailable_koha(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        $this->as('teacher')->getJson(route('api.v1.admin.library-catalog.libraries'))->assertForbidden();
        $this->as('admin')->getJson(route('api.v1.admin.library-catalog.libraries'))->assertStatus(503)->assertJsonPath('code', 'catalog_unavailable');

        config(['catalog.driver' => 'disabled']);
        $this->as('admin')->getJson(route('api.v1.admin.library-catalog.libraries'))->assertStatus(503);
    }

    public function test_the_administrator_saves_the_libraries_with_the_rest_of_the_faculty_and_it_is_audited(): void
    {
        $this->as('admin')->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => ['BGEN', 'BHTM']])->assertOk();

        $this->assertSame(['BGEN', 'BHTM'], $this->faculty->fresh()->koha_libraries);
        $this->assertDatabaseHas('eventos_auditoria', ['accion' => 'academico.facultad.actualizacion', 'recurso_id' => $this->faculty->id]);

        $this->as('admin')->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => []])->assertOk();
        $this->assertSame([], $this->faculty->fresh()->koha_libraries);
    }

    public function test_the_form_sends_the_libraries_as_json_text_and_leaving_them_out_keeps_them(): void
    {
        $this->as('admin')->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => '["BFCCA"]'])->assertOk();
        $this->assertSame(['BFCCA'], $this->faculty->fresh()->koha_libraries);

        $this->as('admin')->patchJson($this->url(), [...$this->data(), 'nombre' => 'Facultad con otro nombre'])->assertOk();

        $this->assertSame(['BFCCA'], $this->faculty->fresh()->koha_libraries);
        $this->assertSame('Facultad con otro nombre', $this->faculty->fresh()->nombre);
    }

    public function test_only_administration_saves_and_the_codes_are_validated(): void
    {
        $this->as('teacher')->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => ['BGEN']])->assertForbidden();
        $this->as('librarian')->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => ['BGEN']])->assertForbidden();

        $this->as('admin');
        $this->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => 'BGEN'])->assertJsonValidationErrors(['catalog_libraries']);
        $this->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => ['BGEN', 'BGEN']])->assertJsonValidationErrors(['catalog_libraries.0']);
        $this->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => ['mal código']])->assertJsonValidationErrors(['catalog_libraries.0']);
        $this->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => [str_repeat('A', 21)]])->assertJsonValidationErrors(['catalog_libraries.0']);
        $this->patchJson($this->url(), [...$this->data(), 'catalog_libraries' => array_map(fn (int $number): string => "B{$number}", range(1, 21))])->assertJsonValidationErrors(['catalog_libraries']);

        $this->assertSame([], $this->faculty->fresh()->koha_libraries);
    }

    public function test_a_new_faculty_can_be_created_with_its_libraries_or_without_them(): void
    {
        $logo = fn () => $this->transparentPng(600, 180);
        $this->as('admin')->post(route('api.v1.admin.academic.store', 'facultad'), [
            'nombre' => 'Facultad de Salud', 'campus' => CampusLocation::Laguacoto->value, 'logo' => $logo(), 'catalog_libraries' => '["BFCCA"]',
        ], ['Accept' => 'application/json'])->assertSuccessful();
        $this->as('admin')->post(route('api.v1.admin.academic.store', 'facultad'), [
            'nombre' => 'Facultad de Arte', 'campus' => CampusLocation::Matriz->value, 'logo' => $logo(),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame(['BFCCA'], Faculty::query()->where('nombre', 'Facultad de Salud')->firstOrFail()->koha_libraries);
        $this->assertSame([], Faculty::query()->where('nombre', 'Facultad de Arte')->firstOrFail()->koha_libraries);
    }

    public function test_a_new_faculty_validates_its_library_codes(): void
    {
        $this->as('admin')->post(route('api.v1.admin.academic.store', 'facultad'), [
            'nombre' => 'Facultad de Salud', 'campus' => CampusLocation::Laguacoto->value,
            'logo' => $this->transparentPng(600, 180),
            'catalog_libraries' => '["mal código"]',
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors(['catalog_libraries.0']);

        $this->assertDatabaseMissing('facultades', ['nombre_facultad' => 'Facultad de Salud']);
    }

    public function test_the_faculty_listing_tells_which_libraries_it_uses(): void
    {
        $this->faculty->update(['koha_libraries' => ['BGEN']]);

        $payload = $this->as('admin')->getJson(route('api.v1.admin.academic.index'))->assertOk()->json();

        $this->assertSame(['BGEN'], collect($this->findFaculties($payload))->firstWhere('id', $this->faculty->id)['catalog_libraries']);
    }

    public function test_a_teacher_only_searches_the_libraries_of_their_faculty(): void
    {
        $this->fakeKoha();
        $this->faculty->update(['koha_libraries' => ['BGEN', 'BHTM']]);

        $this->search()->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'opac-search.pl')
            && $request['q'] === 'kw,rtrn:inversión AND (branch:BGEN OR branch:BHTM)');
    }

    public function test_a_faculty_without_libraries_searches_all_of_them(): void
    {
        $this->fakeKoha();

        $this->search()->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'opac-search.pl') && ! str_contains((string) $request['q'], 'branch'));
    }

    public function test_the_call_number_comes_from_the_copy_in_the_faculty_library(): void
    {
        $this->fakeKoha(withSecondCopy: true);

        $this->search()->assertOk()->assertJsonPath('data.0.call_number', '658.404 S2411p');

        $this->faculty->update(['koha_libraries' => ['BGEN']]);
        $this->search()->assertOk()->assertJsonPath('data.0.call_number', 'MATRIZ 123');

        $this->faculty->update(['koha_libraries' => ['BFCCA']]);
        $this->search()->assertOk()->assertJsonPath('data.0.call_number', '658.404 S2411p');
    }

    private function url(): string
    {
        return route('api.v1.admin.academic.update', ['facultad', $this->faculty->id]);
    }

    /** @return array<string, string> */
    private function data(): array
    {
        return ['nombre' => $this->faculty->nombre, 'campus' => $this->faculty->campus->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function findFaculties(array $payload): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['faculties']) && is_array($node['faculties'])) {
                $found = [...$found, ...array_filter($node['faculties'], fn (mixed $row): bool => is_array($row) && array_key_exists('catalog_libraries', $row))];
            }
            array_map($walk, $node);
        };
        $walk($payload);

        return array_values($found);
    }

    private function fakeKoha(bool $withSecondCopy = false): void
    {
        $rss = (string) file_get_contents(base_path('tests/Fixtures/koha/search.rss'));
        $record = (string) file_get_contents(base_path('tests/Fixtures/koha/proyectos-de-inversion.marcxml'));
        if ($withSecondCopy) {
            $record = str_replace('</record>', '<datafield tag="952" ind1=" " ind2=" "><subfield code="a">BGEN</subfield><subfield code="b">BGEN</subfield><subfield code="o">MATRIZ 123</subfield></datafield></record>', $record);
        }
        Http::fake(fn (Request $request) => str_contains($request->url(), 'opac-search.pl')
            ? Http::response($rss)
            : (($request['bib'] ?? null) === '999' ? Http::response('error', 500) : Http::response($record)));
    }

    private function search(): TestResponse
    {
        return $this->as('teacher')->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'any', 'q' => 'inversión']));
    }

    private function as(string $who): static
    {
        [$user, $assignment] = $this->people[$who];
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);

        return $this;
    }
}
