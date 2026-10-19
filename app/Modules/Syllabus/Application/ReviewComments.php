<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;

final class ReviewComments
{
    /**
     * @return list<array{stage: string, comment: string, commented_at: string, commented_by: string, revision_number: int}>
     */
    public static function forSyllabus(Syllabus $syllabus, ?string $stage = null): array
    {
        return array_values(SyllabusRevision::query()
            ->where('silabo_id', $syllabus->id)
            ->whereNotNull('correccion_solicitada_en')
            ->when($stage !== null, fn ($query) => $query->where('correccion_etapa', $stage))
            ->with('correctionRequester:id,nombre')
            ->latest('correccion_solicitada_en')
            ->get()
            ->map(fn (SyllabusRevision $revision): array => [
                'stage' => (string) $revision->correccion_etapa,
                'comment' => (string) $revision->correccion_justificacion,
                'commented_at' => $revision->correccion_solicitada_en?->toIso8601String() ?? '',
                'commented_by' => $revision->correctionRequester->nombre,
                'revision_number' => $revision->numero_revision,
            ])
            ->all());
    }
}
