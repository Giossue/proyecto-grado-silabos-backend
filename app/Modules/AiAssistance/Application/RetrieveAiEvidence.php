<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Domain\Contracts\AcademicSourceEmbeddingGateway;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiDocument;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

class RetrieveAiEvidence
{
    public function __construct(private readonly AcademicSourceEmbeddingGateway $embeddings) {}

    /**
     * @return Collection<int, AiDocument>
     */
    public function forSyllabus(Syllabus $syllabus, ?string $query, int $limit): Collection
    {
        $syllabus->loadMissing('convocation');
        $sourceIds = $syllabus->convocation->sources()
            ->where('fuentes_academicas.activo', true)
            ->where('fuentes_academicas.carrera_id', $syllabus->convocation->carrera_id)
            ->pluck('fuentes_academicas.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values();
        if ($sourceIds->isEmpty()) {
            return collect();
        }

        $search = trim(mb_substr((string) $query, 0, 500));
        if ($search !== '') {
            $semantic = $this->semanticSearch($sourceIds->all(), $search, $limit);
            if ($semantic->isNotEmpty()) {
                return $semantic;
            }

            $expression = "to_tsvector('spanish'::regconfig, coalesce(metadatos_documento->>'titulo', '') || ' ' || contenido_documento)";
            $ranked = AiDocument::query()
                ->fromSources($sourceIds->all())
                ->whereRaw("{$expression} @@ plainto_tsquery('spanish', ?)", [$search])
                ->orderByRaw("ts_rank_cd({$expression}, plainto_tsquery('spanish', ?)) DESC", [$search])
                ->inSourceOrder()
                ->limit($limit)
                ->get();
            if ($ranked->isNotEmpty()) {
                return $ranked;
            }
        }

        return AiDocument::query()
            ->fromSources($sourceIds->all())
            ->inSourceOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<int, string>  $sourceIds
     * @return Collection<int, AiDocument>
     */
    private function semanticSearch(array $sourceIds, string $search, int $limit): Collection
    {
        try {
            $vectors = $this->embeddings->embed([$search]);
        } catch (AiContractException|AiGatewayUnavailable) {
            return collect();
        }
        $embedding = $vectors[0] ?? [];
        if ($embedding === []) {
            return collect();
        }

        return AiDocument::query()
            ->fromSources($sourceIds)
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> CAST(? AS vector) ASC', [$this->vectorLiteral($embedding)])
            ->limit($limit)
            ->get();
    }

    /** @param list<float> $vector */
    private function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $value): string => sprintf('%.10F', $value), $vector)).']';
    }
}
