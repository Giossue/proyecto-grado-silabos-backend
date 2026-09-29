<?php

namespace App\Modules\AiAssistance\Application\Queries;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;

class AiHistorySyllabusLookup
{
    public function syllabusId(string $entryId): ?string
    {
        $syllabusId = AiHistoryEntry::query()->find($entryId)?->input('silabo_id');

        return is_string($syllabusId) ? $syllabusId : null;
    }
}
