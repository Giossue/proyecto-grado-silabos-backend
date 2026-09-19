<?php

namespace App\Modules\AiAssistance\Infrastructure\Jobs;

use App\Modules\AiAssistance\Domain\Contracts\AcademicSourceEmbeddingGateway;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AcademicSourceFragment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EmbedAcademicSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $sourceId)
    {
        $this->onQueue('ia');
        $this->timeout = (int) config('ai.embeddings.timeout_seconds') + 30;
    }

    public function handle(AcademicSourceEmbeddingGateway $gateway): void
    {
        if (config('ai.embeddings.driver') !== 'openai') {
            return;
        }

        $fragments = AcademicSourceFragment::query()
            ->where('fuente_academica_id', $this->sourceId)
            ->orderBy('posicion_fragmento')
            ->get(['id', 'ruta_encabezados', 'titulo_fragmento', 'contenido_fragmento']);

        foreach ($fragments->chunk((int) config('ai.embeddings.batch_size')) as $batch) {
            $inputs = array_values($batch
                ->map(fn (AcademicSourceFragment $fragment): string => $this->inputFor($fragment))
                ->values()
                ->all());
            $vectors = $gateway->embed($inputs);
            if (count($vectors) !== $batch->count()) {
                throw new \RuntimeException('La cantidad de embeddings no coincide con la cantidad de fragmentos.');
            }

            foreach ($batch->values() as $index => $fragment) {
                DB::update(
                    'UPDATE fragmentos_fuente_academica SET embedding = CAST(? AS vector) WHERE id = ?',
                    [$this->vectorLiteral($vectors[$index]), $fragment->id],
                );
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        // El contenido y el índice textual permanecen disponibles aunque el proveedor
        // de embeddings falle; el siguiente indexado puede reintentarlo.
    }

    private function inputFor(AcademicSourceFragment $fragment): string
    {
        $path = implode(' > ', $fragment->ruta_encabezados ?? []);
        $title = trim((string) $fragment->titulo_fragmento);

        return trim(implode("\n", array_filter([$path, $title, $fragment->contenido_fragmento])));
    }

    /** @param list<float> $vector */
    private function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $value): string => sprintf('%.10F', $value), $vector)).']';
    }
}
