<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiDocument;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Support\Collection;

class RetrieveAiEvidence
{
    /** @return Collection<int, AiDocument> */
    public function forSyllabus(Syllabus $syllabus, ?string $query, int $limit): Collection
    {
        return collect();
    }
}
