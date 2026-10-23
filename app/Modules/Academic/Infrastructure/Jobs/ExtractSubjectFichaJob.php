<?php

namespace App\Modules\Academic\Infrastructure\Jobs;

use App\Modules\Academic\Application\ExtractFichaProposal;
use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Application\SubjectProfileFields;
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
use Throwable;

#[UniqueFor(600)]
class ExtractSubjectFichaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnce, SerializesModels;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public readonly string $importId, public readonly string $subjectId)
    {
        $this->onQueue('ia');
    }

    public function uniqueId(): string
    {
        return $this->importId.':'.$this->subjectId;
    }

    public function handle(ExtractFichaProposal $extractor, SubjectProfileFields $profile, FichaFieldValues $values): void
    {
        $import = FichaImport::query()->find($this->importId);
        if ($import === null || $import->estado_importacion !== FichaImport::RUNNING) {
            return;
        }
        $subject = collect($import->propuesta_importacion['subjects'] ?? [])->firstWhere('subject_id', $this->subjectId);
        if (! is_array($subject)) {
            return;
        }
        if (($subject['state'] ?? '') !== 'pendiente') {
            return;
        }
        $blocks = json_decode((string) Storage::disk('private')->get("importaciones-ficha/{$import->id}/bloques.json"), true) ?: [];
        $fields = array_values($profile->fields()->map(fn ($field): array => $values->schema($field))->all());
        $subjects = array_map(fn (array $entry): array => ['name' => $entry['name'], 'code' => $entry['code']], $import->propuesta_importacion['subjects'] ?? []);
        $this->store($extractor->execute(['code' => $subject['code'], 'name' => $subject['name'], 'cycle' => $subject['cycle']], $fields, $blocks, $subjects, $import->career?->nombre));
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
            $proposal = $import->propuesta_importacion ?? [];
            $proposal['subjects'] ??= [];
            foreach ($proposal['subjects'] as $index => $entry) {
                if ($entry['subject_id'] === $this->subjectId) {
                    $proposal['subjects'][$index] = [...$entry, ...$patch];
                }
            }
            $pending = collect($proposal['subjects'])->contains(fn (array $entry): bool => $entry['state'] === 'pendiente');
            if (! $pending) {
                $proposal['timing']['ready_at'] = now()->toIso8601String();
            }
            $import->update(['propuesta_importacion' => $proposal] + ($pending ? [] : ['estado_importacion' => FichaImport::READY]));
        });
    }
}
