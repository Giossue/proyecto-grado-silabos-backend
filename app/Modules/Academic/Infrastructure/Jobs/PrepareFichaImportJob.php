<?php

namespace App\Modules\Academic\Infrastructure\Jobs;

use App\Modules\Academic\Application\FichaDocumentText;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PrepareFichaImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $importId)
    {
        $this->onQueue('ia');
    }

    public function handle(FichaDocumentText $reader): void
    {
        $import = FichaImport::query()->find($this->importId);
        if ($import === null || $import->estado_importacion !== FichaImport::PENDING) {
            return;
        }
        $import->update(['estado_importacion' => FichaImport::RUNNING]);
        $disk = Storage::disk('private');
        $blocks = [];
        $documents = $import->documentos_importacion;
        foreach ($documents as $index => $document) {
            $reading = ['index' => $index + 1, 'total' => count($documents), 'name' => $document['name'], 'ocr' => null, 'started_at' => now()->toIso8601String()];
            $publish = function (?string $ocr) use (&$reading): void {
                if ($reading['ocr'] === $ocr && $ocr !== null) {
                    return;
                }
                $reading['ocr'] = $ocr;
                FichaImport::query()->whereKey($this->importId)->where('estado_importacion', FichaImport::RUNNING)
                    ->update(['propuesta_importacion' => ['reading' => $reading]]);
            };
            $publish(null);
            $blocks = [...$blocks, ...$reader->blocks($disk->path($document['path']), $document['name'], $publish)];
        }
        if ($blocks === []) {
            throw new RuntimeException('Los documentos no tienen texto legible.');
        }
        if ($import->fresh()?->estado_importacion !== FichaImport::RUNNING) {
            return;
        }
        $disk->put("importaciones-ficha/{$import->id}/bloques.json", json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $subjects = Subject::query()->where('carrera_id', $import->carrera_id)->where('activo', true)->orderBy('ciclo')->orderBy('orden_en_ciclo')->get();
        $import->update(['propuesta_importacion' => ['subjects' => $subjects->map(fn (Subject $subject): array => [
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
