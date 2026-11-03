<?php

namespace Tests\Feature\Library;

use App\Models\User;
use Closure;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\Support\ConfiguresFacultyLogos;
use Tests\TestCase;

class KohaResponseLimitTest extends TestCase
{
    use ConfiguresFacultyLogos;
    use RefreshDatabase;

    private const LIMIT = 2048;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->configureFacultyLogos();
        config(['catalog.driver' => 'koha', 'catalog.koha.base_url' => 'http://koha.test', 'catalog.koha.response_bytes' => self::LIMIT]);
        $admin = User::query()->where('correo_electronico', 'admin@silabos.test')->firstOrFail();
        $this->actingAs($admin)->withSession(['active_role_assignment_id' => $admin->roleAssignments()->firstOrFail()->id]);
    }

    public function test_every_koha_request_aborts_the_download_once_it_passes_the_limit(): void
    {
        $progress = null;
        Http::globalMiddleware(function (callable $handler) use (&$progress): Closure {
            return function (RequestInterface $request, array $options) use ($handler, &$progress) {
                $progress = $options['progress'] ?? null;

                return $handler($request, $options);
            };
        });
        Http::fake(['*/api/v1/public/libraries' => Http::response([['library_id' => 'BGEN', 'name' => 'MATRIZ']])]);

        $this->getJson(route('api.v1.admin.library-catalog.libraries'))->assertOk()->assertJsonPath('data.0.id', 'BGEN');

        $this->assertIsCallable($progress);
        $this->assertFalse($progress(self::LIMIT, self::LIMIT, 0, 0));
        $this->assertFalse($progress(0, 100, 0, 0));
        $this->assertTrue($progress(self::LIMIT + 1, 0, 0, 0), 'El tamaño anunciado ya supera el límite.');
        $this->assertTrue($progress(0, self::LIMIT + 1, 0, 0), 'Lo recibido supera el límite sin tamaño anunciado.');
    }

    public function test_an_oversized_library_list_is_reported_as_unavailable(): void
    {
        $libraries = array_map(fn (int $number): array => ['library_id' => "B{$number}", 'name' => str_repeat('Biblioteca ', 5)], range(1, 100));
        Http::fake(['*/api/v1/public/libraries' => Http::response($libraries)]);

        $this->getJson(route('api.v1.admin.library-catalog.libraries'))->assertStatus(503)->assertJsonPath('code', 'catalog_unavailable');
    }
}
