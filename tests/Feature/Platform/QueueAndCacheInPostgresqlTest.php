<?php

namespace Tests\Feature\Platform;

use App\Modules\Operations\Infrastructure\Jobs\PlatformSmokeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueAndCacheInPostgresqlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string|null>  $environment
     * @return array<string, mixed>
     */
    private function configWith(string $file, array $environment): array
    {
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = $_ENV[$name] ?? null;
            $this->setEnvironment($name, $value);
        }

        try {
            return require config_path($file);
        } finally {
            foreach ($previous as $name => $value) {
                $this->setEnvironment($name, $value);
            }
        }
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    public function test_queue_and_cache_default_to_the_database_driver(): void
    {
        $queue = $this->configWith('queue.php', ['QUEUE_CONNECTION' => null]);
        $cache = $this->configWith('cache.php', ['CACHE_STORE' => null]);

        $this->assertSame('database', $queue['default']);
        $this->assertSame('database', $queue['connections']['database']['driver']);
        $this->assertSame('cola_trabajos', $queue['connections']['database']['table']);
        $this->assertSame('database', $cache['default']);
        $this->assertSame('datos_temporales', $cache['stores']['database']['table']);
        $this->assertSame('bloqueos_temporales', $cache['stores']['database']['lock_table']);
        $this->assertArrayNotHasKey('redis', $queue['connections']);
        $this->assertArrayNotHasKey('redis', $cache['stores']);
    }

    public function test_a_server_still_saying_redis_resolves_to_the_database(): void
    {
        $queue = $this->configWith('queue.php', ['QUEUE_CONNECTION' => 'redis']);
        $cache = $this->configWith('cache.php', ['CACHE_STORE' => 'redis']);

        $this->assertSame('database', $queue['default']);
        $this->assertSame('database', $cache['default']);
    }

    public function test_other_drivers_are_respected(): void
    {
        $queue = $this->configWith('queue.php', ['QUEUE_CONNECTION' => 'sync']);
        $cache = $this->configWith('cache.php', ['CACHE_STORE' => 'array']);

        $this->assertSame('sync', $queue['default']);
        $this->assertSame('array', $cache['default']);
    }

    public function test_a_queued_job_is_stored_in_postgresql_and_the_worker_processes_it(): void
    {
        dispatch((new PlatformSmokeJob('CP-N-database-queue'))->onConnection('database'));

        $this->assertSame(1, DB::table('cola_trabajos')->where('queue', 'critica')->count());

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'critica']);

        $this->assertSame(0, DB::table('cola_trabajos')->count());
    }

    public function test_cache_values_and_locks_live_in_postgresql(): void
    {
        $store = Cache::store('database');

        $store->put('clave-de-prueba', 'valor', 60);
        $this->assertSame('valor', $store->get('clave-de-prueba'));
        $this->assertSame(1, DB::table('datos_temporales')->count());

        $lock = $store->lock('bloqueo-de-prueba', 30);

        $this->assertTrue($lock->get());
        $this->assertSame(1, DB::table('bloqueos_temporales')->count());

        $lock->release();

        $this->assertSame(0, DB::table('bloqueos_temporales')->count());
    }
}
