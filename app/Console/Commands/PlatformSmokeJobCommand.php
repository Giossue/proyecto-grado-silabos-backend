<?php

namespace App\Console\Commands;

use App\Modules\Operations\Infrastructure\Jobs\PlatformSmokeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PlatformSmokeJobCommand extends Command
{
    protected $signature = 'platform:smoke-job {--key= : Clave idempotente opcional}';

    protected $description = 'Despacha un trabajo mínimo para comprobar PostgreSQL y la cola';

    public function handle(): int
    {
        $key = $this->option('key');
        $idempotencyKey = is_string($key) && $key !== '' ? $key : 'smoke:'.Str::uuid();
        PlatformSmokeJob::dispatch($idempotencyKey);

        $this->components->info("Trabajo de verificación {$idempotencyKey} despachado.");

        return self::SUCCESS;
    }
}
