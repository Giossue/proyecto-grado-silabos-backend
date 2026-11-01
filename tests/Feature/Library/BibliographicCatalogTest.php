<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BibliographicCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{User, RoleAssignment}> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        foreach (['teacher' => 'docente', 'librarian' => 'bibliotecario'] as $key => $mail) {
            $user = User::query()->where('correo_electronico', "{$mail}@silabos.test")->firstOrFail();
            $this->people[$key] = [$user, $user->roleAssignments()->firstOrFail()];
        }
        config(['catalog.driver' => 'koha', 'catalog.koha.base_url' => 'http://koha.test']);
    }

    public function test_a_teacher_finds_a_book_by_isbn_with_its_data(): void
    {
        $this->fakeKoha(failing: '999');

        $this->search('isbn', '978-956-343-106-3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'id' => '10525',
                'title' => 'Proyectos de inversión',
                'author' => 'Sapag Chain, Nassir',
                'year' => '2011',
                'place' => 'Santiago de Chile, Chile',
                'publisher' => 'Pearson Educación',
                'isbn' => '9789563431063',
                'call_number' => '658.404 S2411p',
            ]);
    }

    public function test_each_search_field_uses_its_own_koha_index(): void
    {
        $this->fakeKoha();

        foreach (['author' => 'au,rtrn:Sapag', 'title' => 'ti,rtrn:Sapag', 'any' => 'kw,rtrn:Sapag'] as $field => $query) {
            $this->search($field, 'Sapag')->assertOk();
            Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'opac-search.pl')
                && ($request['q'] ?? null) === $query && ($request['format'] ?? null) === 'rss2');
        }
    }

    public function test_it_asks_koha_for_one_page_of_twenty_and_reports_the_total(): void
    {
        $this->fakeKoha(total: 45);

        $this->search('any', 'inversión', 3)
            ->assertOk()
            ->assertJsonPath('meta', ['current_page' => 3, 'last_page' => 3, 'per_page' => 20, 'from' => 41, 'to' => 42, 'total' => 45]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'opac-search.pl')
            && (int) $request['count'] === 20 && (int) $request['offset'] === 40);
    }

    public function test_the_first_page_starts_at_the_beginning(): void
    {
        $this->fakeKoha(total: 45);

        $this->search('any', 'inversión')->assertOk()->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.from', 1);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'opac-search.pl') && (int) $request['offset'] === 0);
    }

    public function test_no_results_still_describes_one_empty_page(): void
    {
        Http::fake(['*' => Http::response('<rss version="2.0" xmlns:opensearch="http://a9.com/-/spec/opensearch/1.1/"><channel><opensearch:totalResults>0</opensearch:totalResults></channel></rss>')]);

        $this->search('any', 'nada')->assertOk()->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'from' => null, 'to' => null, 'total' => 0]);
    }

    public function test_the_page_must_be_a_number_between_1_and_100(): void
    {
        Http::fake();
        $this->as('teacher');

        foreach ([0, 101, 'x'] as $page) {
            $this->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'any', 'q' => 'inversión', 'page' => $page]))->assertJsonValidationErrors(['page']);
        }

        Http::assertNothingSent();
    }

    public function test_every_word_is_searched_as_a_prefix_and_all_must_appear(): void
    {
        $this->fakeKoha();

        $this->search('title', 'proyec inver')->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'opac-search.pl')
            && $request['q'] === 'ti,rtrn:proyec AND ti,rtrn:inver');
    }

    public function test_the_term_cannot_inject_search_syntax(): void
    {
        $this->fakeKoha();

        $this->search('title', "inversión\" or au:x* (y)\\\n AND z")->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'opac-search.pl')
            && $request['q'] === 'ti,rtrn:inversión AND ti,rtrn:au AND ti,rtrn:x AND ti,rtrn:y AND ti,rtrn:z');
    }

    public function test_a_term_without_letters_or_numbers_is_not_searched(): void
    {
        Http::fake();

        $this->search('any', '** ""')->assertOk()->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    public function test_an_isbn_that_is_not_an_isbn_is_not_searched(): void
    {
        Http::fake();

        $this->search('isbn', '12345')->assertOk()->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    public function test_a_book_that_cannot_be_read_is_skipped(): void
    {
        $this->fakeKoha(failing: '999');

        $this->search('any', 'inversión')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_no_results_is_an_empty_list(): void
    {
        Http::fake(['*' => Http::response('<rss version="2.0"><channel></channel></rss>')]);

        $this->search('any', 'nada')->assertOk()->assertJsonPath('data', []);
    }

    public function test_it_reports_an_unavailable_catalog(): void
    {
        Http::fake(fn () => throw new ConnectionException('sin red'));
        $this->search('any', 'inversión')->assertStatus(503)->assertJsonPath('code', 'catalog_unavailable');

        Http::fake(['*' => Http::response('error', 500)]);
        $this->search('any', 'inversión')->assertStatus(503);

        Http::fake(['*' => Http::response('no es xml')]);
        $this->search('any', 'inversión')->assertStatus(503);
    }

    public function test_it_is_disabled_by_default_and_needs_a_configured_address(): void
    {
        Http::fake();
        config(['catalog.driver' => 'disabled']);
        $this->search('any', 'inversión')->assertStatus(503)->assertJsonPath('code', 'catalog_unavailable');

        config(['catalog.driver' => 'koha', 'catalog.koha.base_url' => '']);
        $this->search('any', 'inversión')->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_only_a_teacher_can_search_and_the_search_is_validated(): void
    {
        Http::fake();
        $this->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'any', 'q' => 'inversión']))->assertUnauthorized();

        $this->as('librarian')
            ->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'any', 'q' => 'inversión']))
            ->assertForbidden();

        $this->as('teacher');
        $this->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'any']))->assertJsonValidationErrors(['q']);
        $this->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'otro', 'q' => 'inversión']))->assertJsonValidationErrors(['field']);
        $this->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'any', 'q' => 'a']))->assertJsonValidationErrors(['q']);
        $this->getJson(route('api.v1.teacher.library-catalog.search', ['field' => 'any', 'q' => str_repeat('a', 121)]))->assertJsonValidationErrors(['q']);

        Http::assertNothingSent();
    }

    public function test_the_search_is_limited_per_user(): void
    {
        $this->fakeKoha();
        config(['catalog.limits.requests_per_minute' => 2]);

        $this->search('any', 'inversión')->assertOk();
        $this->search('any', 'inversión')->assertOk();
        $this->search('any', 'inversión')->assertStatus(429);
    }

    private function fakeKoha(?string $failing = null, int $total = 2): void
    {
        $rss = str_replace('<opensearch:totalResults>2<', "<opensearch:totalResults>{$total}<", (string) file_get_contents(base_path('tests/Fixtures/koha/search.rss')));
        $record = (string) file_get_contents(base_path('tests/Fixtures/koha/proyectos-de-inversion.marcxml'));
        Http::fake(function (Request $request) use ($rss, $record, $failing) {
            if (str_contains($request->url(), 'opac-search.pl')) {
                return Http::response($rss);
            }

            return $failing !== null && ($request['bib'] ?? null) === $failing
                ? Http::response('error', 500)
                : Http::response($record);
        });
    }

    private function search(string $field, string $term, ?int $page = null): TestResponse
    {
        $this->as('teacher');

        return $this->getJson(route('api.v1.teacher.library-catalog.search', ['field' => $field, 'q' => $term, ...($page === null ? [] : ['page' => $page])]));
    }

    private function as(string $who): static
    {
        [$user, $assignment] = $this->people[$who];
        $this->actingAs($user)->withSession(['active_role_assignment_id' => $assignment->id]);

        return $this;
    }
}
