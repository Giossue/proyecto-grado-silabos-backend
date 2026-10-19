<?php

namespace App\Modules\Academic\Infrastructure\Jobs;

use App\Modules\Academic\Application\FichaEvidence;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Domain\Contracts\FichaExtractionGateway;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Support\Queue\RunsOnce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

#[UniqueFor(600)]
class ExtractSubjectFichaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnce, SerializesModels;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(public readonly string $importId, public readonly string $subjectId)
    {
        $this->onQueue('ia');
    }

    public function uniqueId(): string
    {
        return $this->importId.':'.$this->subjectId;
    }

    public function handle(FichaExtractionGateway $gateway, FichaEvidence $evidence, SubjectProfileFields $profile): void
    {
        $import = FichaImport::query()->find($this->importId);
        if ($import === null || $import->estado_importacion !== FichaImport::RUNNING) {
            return;
        }
        $subject = collect($import->propuesta_importacion['subjects'] ?? [])->firstWhere('subject_id', $this->subjectId);
        if (! is_array($subject)) {
            return;
        }
        $blocks = json_decode((string) Storage::disk('private')->get("importaciones-ficha/{$import->id}/bloques.json"), true) ?: [];
        $fragments = $evidence->for($subject['name'], $subject['code'], $blocks);
        if ($fragments === []) {
            $this->store(['state' => 'sin_evidencia']);

            return;
        }
        $fields = $profile->fields()->map(fn ($field): array => ['key' => $field->clave, 'label' => $field->etiqueta !== '' ? $field->etiqueta : $field->clave])->values()->all();
        $proposal = $gateway->propose(['code' => $subject['code'], 'name' => $subject['name'], 'cycle' => $subject['cycle']], $fields, $fragments);
        $values = [];
        foreach ($fields as $field) {
            $value = $proposal[$field['key']] ?? null;
            $clean = is_array($value)
                ? array_slice(array_values(array_filter(array_map(fn (mixed $item): string => is_scalar($item) ? Str::limit(trim((string) $item), 1500, '') : '', $value), fn (string $item): bool => $item !== '')), 0, 40)
                : (is_scalar($value) ? Str::limit(trim((string) $value), 4000, '') : '');
            if ($clean !== '' && $clean !== []) {
                $values[$field['key']] = $clean;
            }
        }
        $this->store(['state' => $values === [] ? 'sin_evidencia' : 'lista', 'values' => (object) $values]);
    }

    public function failed(Throwable $exception): void
    {
        $this->store(['state' => 'fallida', 'error' => 'La IA no pudo proponer esta ficha.']);
    }

    /** @param array<string, mixed> $patch */
    private function store(array $patch): void
    {
        DB::transaction(function () use ($patch): void {
            $import = FichaImport::query()->lockForUpdate()->find($this->importId);
            if ($import === null || $import->estado_importacion !== FichaImport::RUNNING) {
                return;
            }
            $proposal = ['subjects' => $import->propuesta_importacion['subjects'] ?? []];
            foreach ($proposal['subjects'] as $index => $entry) {
                if ($entry['subject_id'] === $this->subjectId) {
                    $proposal['subjects'][$index] = [...$entry, ...$patch];
                }
            }
            $pending = collect($proposal['subjects'])->contains(fn (array $entry): bool => $entry['state'] === 'pendiente');
            $import->update(['propuesta_importacion' => $proposal] + ($pending ? [] : ['estado_importacion' => FichaImport::READY]));
        });
    }
}
