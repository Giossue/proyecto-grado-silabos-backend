<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessClosing;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Illuminate\Support\Facades\DB;

class CloseEndedProcesses
{
    public function __construct(
        private readonly ProcessClosing $closing,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(): int
    {
        $today = InstitutionDate::todayOn();
        $ids = SyllabusProcess::query()->inProgress()
            ->where('entrega_en', '<', InstitutionDate::startOfDay($today)->utc())
            ->pluck('id');

        $closed = 0;
        foreach ($ids as $id) {
            $closed += DB::transaction(function () use ($id, $today): int {
                $locked = SyllabusProcess::query()->whereKey($id)->lockForUpdate()->first();
                if ($locked === null
                    || ! in_array($locked->estado, [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED], true)
                    || InstitutionDate::dateOf($locked->entrega_en) >= $today) {
                    return 0;
                }
                $previous = $locked->estado;
                $this->closing->close($locked);
                $this->audit->execute(
                    actorId: null,
                    roleAssignmentId: null,
                    action: 'proceso_silabos.'.SyllabusProcess::STATE_CLOSED,
                    resourceType: 'proceso_silabos',
                    resourceId: $locked->id,
                    result: 'exito',
                    metadata: ['previous_state' => $previous, 'automatic' => true],
                );

                return 1;
            });
        }

        return $closed;
    }
}
