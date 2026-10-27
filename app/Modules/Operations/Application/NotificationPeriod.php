<?php

namespace App\Modules\Operations\Application;

use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;

final class NotificationPeriod
{
    public function of(string $resourceType, string $resourceId): ?string
    {
        $processId = match ($resourceType) {
            'proceso_silabos' => $resourceId,
            'convocatoria' => Convocation::query()->whereKey($resourceId)->value('convocatoria_universidad_id'),
            'silabo' => $this->processOfSyllabus($resourceId),
            'artefacto_exportacion' => $this->processOfSyllabus(
                ExportArtifact::query()->with('revision:revision_silabo_id,silabo_id')->find($resourceId)?->revision?->silabo_id,
            ),
            default => null,
        };

        return is_string($processId)
            ? SyllabusProcess::query()->whereKey($processId)->value('periodo_academico_id')
            : null;
    }

    private function processOfSyllabus(?string $syllabusId): ?string
    {
        if ($syllabusId === null) {
            return null;
        }
        $convocationId = Syllabus::query()->whereKey($syllabusId)->value('convocatoria_carrera_id');

        return is_string($convocationId)
            ? Convocation::query()->whereKey($convocationId)->value('convocatoria_universidad_id')
            : null;
    }
}
