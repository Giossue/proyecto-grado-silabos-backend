<?php

namespace App\Modules\AiAssistance\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Recibe trabajos serializados anteriores sin consultar ni modificar el índice. */
final class EmbedAcademicSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $sourceId)
    {
        $this->onQueue('ia');
    }

    public function handle(): void {}
}
