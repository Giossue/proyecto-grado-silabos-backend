<?php

namespace App\Modules\Academic\Infrastructure\Jobs;

use App\Modules\Academic\Application\PrepareCurricularImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PrepareCurricularImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [15, 30, 60];

    public function __construct(public readonly string $importId)
    {
        $this->onQueue('integraciones');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('curricular-import:'.$this->importId))->releaseAfter(15)->expireAfter(150)];
    }

    public function handle(PrepareCurricularImport $prepare): void
    {
        if (! $prepare->step($this->importId)) {
            self::dispatch($this->importId)->delay(now()->addSeconds(15))->afterCommit();
        }
    }

    public function failed(Throwable $exception): void
    {
        app(PrepareCurricularImport::class)->fail($this->importId);
    }
}
