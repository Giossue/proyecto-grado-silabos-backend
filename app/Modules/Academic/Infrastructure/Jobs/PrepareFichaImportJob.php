<?php

namespace App\Modules\Academic\Infrastructure\Jobs;

use App\Modules\Academic\Application\FichaDocumentText;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Support\Queue\RunsOnce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[UniqueFor(1800)]
class PrepareFichaImportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnce, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $importId)
    {
        $this->onQueue('ia');
    }

    public function uniqueId(): string
    {
        return $this->importId;
    }

    public function handle(FichaDocumentText $reader): void
    {
        $import = FichaImport::query()->find($this->importId);
        if ($import === null || $import->estado_importacion !== FichaImport::PENDING) {
            return;
        }
        $started = microtime(true);
        $timing = ['started_at' => now()->toIso8601String(), 'documents' => []];
        $import->update(['estado_importacion' => FichaImport::RUNNING, 'propuesta_importacion' => ['timing' => $timing]]);
        $disk = Storage::disk('private');
        $blocks = [];
        $documents = $import->documentos_importacion;
        $cache = [];
        foreach ($documents as $index => $document) {
            if ($import->fresh()?->estado_importacion !== FichaImport::RUNNING) {
                return;
            }
            $reading = ['index' => $index + 1, 'total' => count($documents), 'name' => $document['name'], 'ocr' => null, 'started_at' => now()->toIso8601String()];
            $publish = function (?string $ocr) use (&$reading, &$timing): void {
                if ($reading['ocr'] === $ocr && $ocr !== null) {
                    return;
                }
                $reading['ocr'] = $ocr;
                FichaImport::query()->whereKey($this->importId)->where('estado_importacion', FichaImport::RUNNING)
                    ->update(['propuesta_importacion' => ['reading' => $reading, 'timing' => $timing]]);
            };
            $publish(null);
            $path = $disk->path($document['path']);
            $hash = hash_file('sha256', $path);
            $cached = isset($cache[$hash]);
            $parsed = $cache[$hash] ??= $reader->read($path, $document['name'], $publish);
            $timing['documents'][] = ['index' => $index + 1, 'method' => $parsed['method'], 'duration_ms' => $cached ? 0 : $parsed['duration_ms'], 'cached' => $cached];
            if (! $cached) {
                $blocks = [...$blocks, ...array_map(fn (array $block): array => [...$block, 'source' => $index], $parsed['blocks'])];
            }
        }
        if ($blocks === []) {
            throw new RuntimeException('Los documentos no tienen texto legible.');
        }
        if (! FichaImport::query()->whereKey($this->importId)->where('estado_importacion', FichaImport::RUNNING)->exists()) {
            return;
        }
        $disk->put("importaciones-ficha/{$import->id}/bloques.json", json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $subjects = Subject::query()->where('carrera_id', $import->carrera_id)->where('activo', true)->orderBy('ciclo')->orderBy('orden_en_ciclo')->get();
        $timing['reading_ms'] = (int) round((microtime(true) - $started) * 1000);
        if ($subjects->isEmpty()) {
            $timing['ready_at'] = now()->toIso8601String();
        }
        $import->update(['estado_importacion' => $subjects->isEmpty() ? FichaImport::READY : FichaImport::RUNNING, 'propuesta_importacion' => ['timing' => $timing, 'subjects' => $subjects->map(fn (Subject $subject): array => [
            'subject_id' => $subject->id, 'code' => $subject->codigo_asignatura, 'name' => $subject->nombre, 'cycle' => $subject->ciclo,
            'state' => 'pendiente', 'values' => new \stdClass, 'error' => null,
        ])->all()]]);
        foreach ($subjects as $subject) {
            ExtractSubjectFichaJob::dispatch($import->id, $subject->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        FichaImport::query()->whereKey($this->importId)->whereIn('estado_importacion', [FichaImport::PENDING, FichaImport::RUNNING])->update([
            'estado_importacion' => FichaImport::FAILED,
            'mensaje_error_importacion' => $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudieron leer los documentos.',
        ]);
    }
}
