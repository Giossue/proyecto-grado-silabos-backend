<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\CanonicalHasher;

class AiEvidenceCollector
{
    public function __construct(
        private readonly CanonicalHasher $hasher,
        private readonly RetrieveAiEvidence $retriever,
    ) {}

    /**
     * @return array{
     *   items: list<array{
     *     source_id: string, source_name: string, fragment_id: string,
     *     heading_path: list<string>, excerpt: string, fingerprint: string
     *   }>,
     *   fingerprint: string,
     *   too_many: bool
     * }
     */
    public function collect(Syllabus $syllabus, ?string $query = null): array
    {
        $syllabus->loadMissing('convocation');
        $sources = $syllabus->convocation->sources()
            ->where('fuentes_academicas.activo', true)
            ->where('fuentes_academicas.carrera_id', $syllabus->convocation->carrera_id)
            ->orderBy('fuentes_academicas.id')
            ->get();

        $limit = (int) config('ai.limits.evidence_items');
        $items = [];
        $names = $sources->pluck('nombre', 'id');
        foreach ($this->retriever->forSyllabus($syllabus, $query, $limit) as $fragment) {
            $path = array_values(array_filter($fragment->headingPath(), fn (string $heading): bool => $heading !== ''));
            $sourceName = (string) $names->get($fragment->sourceId(), '');
            $items[] = [
                'source_id' => $fragment->sourceId(),
                'source_name' => mb_substr($path === [] ? $sourceName : $sourceName.' · '.implode(' › ', $path), 0, 180),
                'fragment_id' => $fragment->id,
                'heading_path' => $path,
                'excerpt' => mb_substr($fragment->contenido_documento, 0, (int) config('ai.limits.evidence_excerpt_characters')),
                'fingerprint' => $fragment->fingerprint(),
            ];
        }

        return [
            'items' => $items,
            'fingerprint' => $this->hasher->hash($sources->map(fn (AcademicSource $source): array => [
                'source_id' => $source->id,
                'fingerprint' => $this->hasher->hash($source->contenido),
            ])->all()),
            'too_many' => false,
        ];
    }
}
