<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\Actions\RegenerateProcessSyllabi;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonInterface;

final class ProcessTemplateChange
{
    public function __construct(
        private readonly RegenerateProcessSyllabi $regenerate,
        private readonly RecordAuditEvent $audit,
    ) {}

    public static function since(?CarbonInterface $moment): ?CarbonInterface
    {
        if ($moment === null) {
            return null;
        }
        $changedAt = SyllabusTemplate::query()->first()?->plantilla_modificada_en;

        return $changedAt instanceof CarbonInterface && $changedAt->greaterThan($moment) ? $changedAt : null;
    }

    /** @param  array<string, int>  $counts */
    public function adapt(SyllabusProcess $locked, CarbonInterface $changedAt, array $counts, string $actorId, string $roleAssignmentId): void
    {
        $this->regenerate->regenerate($locked, $changedAt->toISOString());
        $metadata = ['total' => array_sum($counts)];
        foreach ($counts as $state => $count) {
            $metadata['estado_'.$state] = $count;
        }
        $this->audit->execute(
            actorId: $actorId,
            roleAssignmentId: $roleAssignmentId,
            action: 'proceso.plantilla_rehecho',
            resourceType: 'proceso_silabos',
            resourceId: $locked->id,
            result: 'exito',
            metadata: $metadata,
        );
    }
}
