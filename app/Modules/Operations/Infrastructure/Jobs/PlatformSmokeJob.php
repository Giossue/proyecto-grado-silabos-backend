<?php

namespace App\Modules\Operations\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformSmokeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(public readonly string $key)
    {
        $this->onQueue('critica');
    }

    public function handle(): void
    {
        DB::selectOne('select 1');
        Log::info('platform-smoke-ok', ['key' => $this->key]);
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
