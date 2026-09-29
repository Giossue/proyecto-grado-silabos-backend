<?php

namespace App\Modules\Documents\Application\Queries;

use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;

class ExportArtifactLookup
{
    /** @return array{revision_id: string, syllabus_id: string}|null */
    public function revisionOf(string $artifactId): ?array
    {
        $revision = ExportArtifact::query()->with('revision.syllabus')->find($artifactId)?->revision;
        $syllabus = $revision?->syllabus;
        if ($revision === null || $syllabus === null) {
            return null;
        }

        return ['revision_id' => $revision->id, 'syllabus_id' => $syllabus->id];
    }
}
