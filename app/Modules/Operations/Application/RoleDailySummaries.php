<?php

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Application\ReviewStage;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;

/** Qué dice el resumen diario de Docente y de Biblioteca (el de Coordinación está en CoordinatorDailySummary). */
final class RoleDailySummaries
{
    public function __construct(
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ConvocationSchedule $schedule,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    /**
     * Sílabos por elaborar o enviar y por corregir de cada docente, en convocatorias abiertas con el plazo vigente.
     *
     * @return array<string, list<string>> docente => líneas
     */
    public function teachers(): array
    {
        $lines = [];
        $convocations = Convocation::query()->with('career:id,nombre')
            ->where('estado', Convocation::STATE_OPEN)
            ->whereHas('process', fn ($process) => $process->where('estado', SyllabusProcess::STATE_OPEN))
            ->get();
        foreach ($convocations as $convocation) {
            $due = $this->schedule->draftDeadline($convocation);
            if (InstitutionDate::dateOf($due) < InstitutionDate::todayOn()) {
                continue;
            }
            $toWrite = $this->recipients->teacherSyllabusCounts([$convocation->id], ['en_revision', 'correccion_solicitada', 'aprobado']);
            $toFix = $this->recipients->teacherSyllabusCounts([$convocation->id], ['sin_iniciar', 'borrador', 'en_revision', 'aprobado']);
            foreach (array_unique([...array_keys($toWrite), ...array_keys($toFix)]) as $teacherId) {
                $parts = [];
                if (($toWrite[$teacherId] ?? 0) > 0) {
                    $parts[] = $toWrite[$teacherId] === 1 ? '1 sílabo por elaborar o enviar' : "{$toWrite[$teacherId]} sílabos por elaborar o enviar";
                }
                if (($toFix[$teacherId] ?? 0) > 0) {
                    $parts[] = $toFix[$teacherId] === 1 ? '1 sílabo por corregir' : "{$toFix[$teacherId]} sílabos por corregir";
                }
                $lines[(string) $teacherId][] = "{$convocation->career->label()}: ".implode(' y ', $parts).". Entrega hasta el {$this->notifications->date($due)}.";
            }
        }

        return $lines;
    }

    /**
     * Sílabos que esperan la revisión de biblioteca, por carrera. Es igual para todo el personal de biblioteca.
     *
     * @return list<string>
     */
    public function library(): array
    {
        $byCareer = [];
        $syllabi = Syllabus::query()->with('convocation.career:id,nombre')->where('estado', 'en_revision')
            ->whereHas('convocation', fn ($convocation) => $convocation->where('estado', Convocation::STATE_OPEN))
            ->whereHas('convocation.process', fn ($process) => $process->where('estado', SyllabusProcess::STATE_OPEN))
            ->get();
        foreach ($syllabi as $syllabus) {
            if (ReviewStage::of($syllabus) === ReviewStage::LIBRARY) {
                $career = $syllabus->convocation->career->label();
                $byCareer[$career] = ($byCareer[$career] ?? 0) + 1;
            }
        }
        ksort($byCareer);

        return array_map(
            fn (string $career, int $count): string => $count === 1 ? "{$career}: 1 sílabo por revisar." : "{$career}: {$count} sílabos por revisar.",
            array_keys($byCareer),
            array_values($byCareer),
        );
    }
}
