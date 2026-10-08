<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Support\Facades\DB;

/** Finaliza sola cada convocatoria institucional cuando pasa su fecha de entrega. */
class CloseExpiredProcesses
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function execute(): int
    {
        $closed = 0;
        $expired = SyllabusProcess::query()
            ->whereIn('estado', [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED])
            ->whereDate('fecha_entrega', '<', now()->toDateString())
            ->pluck('id');

        foreach ($expired as $id) {
            DB::transaction(function () use ($id, &$closed): void {
                $process = SyllabusProcess::query()->lockForUpdate()->find($id);
                if ($process === null || ! in_array($process->estado, [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED], true)) {
                    return;
                }
                $previous = $process->estado;
                $process->update(['estado' => SyllabusProcess::STATE_CLOSED]);
                Convocation::query()
                    ->where('proceso_id', $process->id)
                    ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
                    ->update(['estado' => Convocation::STATE_CLOSED]);
                $this->audit->execute(
                    actorId: null,
                    roleAssignmentId: null,
                    action: 'proceso_silabos.cerrado',
                    resourceType: 'proceso_silabos',
                    resourceId: $process->id,
                    result: 'exito',
                    metadata: ['previous_state' => $previous, 'automatico' => true],
                );
                $closed++;
            });
        }

        return $closed;
    }
}
