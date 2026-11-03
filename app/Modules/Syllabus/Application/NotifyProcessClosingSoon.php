<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;

final class NotifyProcessClosingSoon
{
    public const DAYS_BEFORE = 3;

    public const EVENT = 'proceso.cierre_proximo';

    public function __construct(
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function execute(): int
    {
        $lastClassDay = InstitutionDate::today()->addDays(self::DAYS_BEFORE)->toDateString();
        $processes = SyllabusProcess::query()->inProgress()->with('academicPeriod')
            ->whereHas('academicPeriod', fn ($query) => $query->whereDate('fecha_fin_periodo', $lastClassDay))
            ->get();

        foreach ($processes as $process) {
            $closesOn = CarbonImmutable::parse($lastClassDay)->format('d/m/Y');
            $this->notifications->execute(
                eventType: self::EVENT,
                deduplicationKey: self::EVENT.":{$process->id}:{$lastClassDay}",
                recipientIds: [
                    ...$this->recipients->teachersWithPendingWork($process),
                    ...$this->recipients->coordinatorsWithPendingWork($process),
                ],
                title: 'La convocatoria se cierra pronto',
                message: "La convocatoria {$process->nombre} se cierra al terminar el {$closesOn}, último día de clases. Lo que no esté aprobado quedará solo para consulta.",
                resourceType: 'proceso_silabos',
                resourceId: $process->id,
                periodId: $process->periodo_academico_id,
            );
        }

        return $processes->count();
    }
}
