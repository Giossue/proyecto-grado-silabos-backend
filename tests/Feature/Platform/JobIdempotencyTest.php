<?php

namespace Tests\Feature\Platform;

use App\Modules\Academic\Infrastructure\Jobs\ExtractSubjectFichaJob;
use App\Modules\Academic\Infrastructure\Jobs\PrepareFichaImportJob;
use App\Modules\AiAssistance\Infrastructure\Jobs\AnswerSyllabusChatJob;
use App\Modules\Documents\Infrastructure\Jobs\GenerateSyllabusExportJob;
use App\Modules\Operations\Infrastructure\Jobs\PlatformSmokeJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobIdempotencyTest extends TestCase
{
    /** @return array<string, array{0: object}> */
    public static function jobs(): array
    {
        return [
            'exportación' => [new GenerateSyllabusExportJob('artefacto-1')],
            'respuesta del chat' => [new AnswerSyllabusChatJob('mensaje-1')],
            'extracción de ficha' => [new ExtractSubjectFichaJob('importacion-1', 'materia-1')],
            'lectura de fichas' => [new PrepareFichaImportJob('importacion-1')],
            'verificación de plataforma' => [new PlatformSmokeJob('smoke-1')],
        ];
    }

    #[DataProvider('jobs')]
    public function test_every_job_is_unique_and_never_overlaps_itself(object $job): void
    {
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertNotSame('', $job->uniqueId());

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame($job->uniqueId(), $middleware[0]->key);
        $this->assertNull($middleware[0]->releaseAfter);
        $this->assertSame($job->timeout + 30, $middleware[0]->expiresAfter);
    }

    public function test_a_duplicate_dispatch_is_dropped_while_the_first_is_pending(): void
    {
        Queue::fake();
        $cache = Cache::store();
        $this->assertInstanceOf(Repository::class, $cache);
        $first = new GenerateSyllabusExportJob('artefacto-duplicado');
        $this->assertTrue((new UniqueLock($cache))->acquire($first));

        $this->assertFalse((new UniqueLock($cache))->acquire(new GenerateSyllabusExportJob('artefacto-duplicado')));
        $this->assertTrue((new UniqueLock($cache))->acquire(new GenerateSyllabusExportJob('otro-artefacto')));
    }
}
