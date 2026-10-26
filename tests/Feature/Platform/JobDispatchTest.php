<?php

namespace Tests\Feature\Platform;

use App\Modules\AiAssistance\Infrastructure\Jobs\AnswerSyllabusChatJob;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Operations\Infrastructure\Jobs\PlatformSmokeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_command_dispatches_the_job_and_the_job_checks_the_database(): void
    {
        Queue::fake();

        $this->artisan('platform:smoke-job', ['--key' => 'CP-N-smoke'])->assertSuccessful();

        Queue::assertPushed(PlatformSmokeJob::class, fn (PlatformSmokeJob $job): bool => $job->key === 'CP-N-smoke');
        Log::spy();
        (new PlatformSmokeJob('CP-N-smoke'))->handle();
        Log::shouldHaveReceived('info')->with('platform-smoke-ok', ['key' => 'CP-N-smoke'])->once();
    }

    public function test_redis_retry_window_exceeds_every_job_timeout(): void
    {
        $jobs = [
            new PlatformSmokeJob('CP-N-timeout'),
            new GenerateSyllabusExportJob('00000000-0000-0000-0000-000000000003'),
            new AnswerSyllabusChatJob('00000000-0000-0000-0000-000000000004'),
        ];
        $longestJobTimeout = max(array_map(
            static fn (object $job): int => $job->timeout,
            $jobs,
        ));

        $this->assertGreaterThan(
            $longestJobTimeout,
            (int) config('queue.connections.redis.retry_after'),
            'REDIS_QUEUE_RETRY_AFTER debe superar el timeout del trabajo más largo.',
        );
    }
}
