<?php

namespace App\Modules\Operations\Infrastructure\Jobs;

use App\Support\Queue\RunsOnce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[UniqueFor(300)]
class PlatformSmokeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnce;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(public readonly string $key)
    {
        $this->onQueue('critica');
    }

    public function uniqueId(): string
    {
        return $this->key;
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
