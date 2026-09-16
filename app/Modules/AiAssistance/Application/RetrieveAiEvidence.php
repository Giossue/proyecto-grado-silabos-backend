<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AcademicSourceFragment;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

class RetrieveAiEvidence
{
    /**
     * @return Collection<int, AcademicSourceFragment>
     */
    public function forSyllabus(Syllabus $syllabus, ?string $query, int $limit): Collection
    {
        $syllabus->loadMissing('convocation');
        $sourceIds = $syllabus->convocation->sources()
            ->where('fuentes_academicas.activo', true)
            ->where('fuentes_academicas.carrera_id', $syllabus->carrera_id)
            ->pluck('fuentes_academicas.id');
        if ($sourceIds->isEmpty()) {
            return collect();
        }

        $search = trim(mb_substr((string) $query, 0, 500));
        if ($search !== '') {
            $expression = "to_tsvector('spanish', coalesce(titulo_fragmento, '') || ' ' || contenido_fragmento)";
            $ranked = AcademicSourceFragment::query()
                ->with('source')
                ->whereIn('fuente_academica_id', $sourceIds)
                ->whereRaw("{$expression} @@ plainto_tsquery('spanish', ?)", [$search])
                ->orderByRaw("ts_rank_cd({$expression}, plainto_tsquery('spanish', ?)) DESC", [$search])
                ->orderBy('fuente_academica_id')
                ->orderBy('posicion_fragmento')
                ->limit($limit)
                ->get();
            if ($ranked->isNotEmpty()) {
                return $ranked;
            }
        }

        return AcademicSourceFragment::query()
            ->with('source')
            ->whereIn('fuente_academica_id', $sourceIds)
            ->orderBy('fuente_academica_id')
            ->orderBy('posicion_fragmento')
            ->limit($limit)
            ->get();
    }
}
