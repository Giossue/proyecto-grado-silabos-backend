<?php

namespace App\Modules\Operations\Application\Actions;

use App\Modules\Operations\Infrastructure\Jobs\PlatformSmokeJob;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Support\Facades\DB;

class DispatchPlatformSmoke
{
    public function execute(string $idempotencyKey): JobExecution
    {
        return DB::transaction(function () use ($idempotencyKey): JobExecution {
            $execution = JobExecution::query()->firstOrCreate(
                ['clave_idempotencia' => $idempotencyKey],
                [
                    'tipo' => 'plataforma.verificacion',
                    'cola' => 'critica',
                    'estado' => 'pendiente',
                ],
            );

            if ($execution->wasRecentlyCreated) {
                PlatformSmokeJob::dispatch($execution->id)->afterCommit();
            }

            return $execution;
        });
    }
}
