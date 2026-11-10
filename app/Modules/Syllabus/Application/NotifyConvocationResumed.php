<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;

/**
 * Avisa al reanudar: el banner de pausa promete «le avisaremos cuando se reanude».
 * Docentes de cada convocatoria que vuelve a estar abierta; Coordinación y Biblioteca, solo si quien reanudó fue
 * Administración: en la pausa nadie edita ni revisa, y el aviso dice que se retoma.
 */
final class NotifyConvocationResumed
{
    public function __construct(
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function convocation(Convocation $convocation): void
    {
        $convocation->loadMissing('career');
        $at = now()->format('YmdHisv');
        foreach (array_keys($this->recipients->teacherSyllabusCounts([$convocation->id])) as $teacherId) {
            $this->notifications->execute(
                eventType: 'convocatoria.reanudada',
                deduplicationKey: "convocatoria.reanudada:{$convocation->id}:{$teacherId}:{$at}",
                recipientIds: [$teacherId],
                title: 'Se reanudó la convocatoria',
                message: "Ya puede seguir con sus sílabos de {$convocation->career->label()}.",
                resourceType: 'convocatoria',
                resourceId: $convocation->id,
            );
        }
    }

    public function process(SyllabusProcess $process): void
    {
        $open = Convocation::query()->with('career')
            ->where('proceso_id', $process->id)
            ->where('estado', Convocation::STATE_OPEN)
            ->get();
        foreach ($open as $convocation) {
            $this->convocation($convocation);
        }

        $process->loadMissing('academicPeriod');
        $at = now()->format('YmdHisv');
        $staff = array_values(array_unique([...$this->recipients->coordinatorsForProcess($process), ...$this->recipients->librarians()]));
        foreach ($staff as $coordinatorId) {
            $this->notifications->execute(
                eventType: 'proceso.reanudado',
                deduplicationKey: "proceso.reanudado:{$process->id}:{$coordinatorId}:{$at}",
                recipientIds: [$coordinatorId],
                title: 'Administración reanudó la convocatoria',
                message: "Se retoman la edición y las revisiones de los sílabos del período {$process->academicPeriod->codigo}.",
                resourceType: 'proceso_silabos',
                resourceId: $process->id,
            );
        }
    }
}
