<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ConvocationSchedule
{
    public const STAGE_START = 'inicio';

    public const STAGE_DRAFT = 'borrador';

    public function startsAt(Convocation $convocation): CarbonImmutable
    {
        return $convocation->process->inicia_en;
    }

    public function draftDeadline(Convocation $convocation): CarbonImmutable
    {
        return $convocation->process->entrega_en;
    }

    /** @throws ValidationException */
    public function assertOpenForSubmission(Convocation $convocation): void
    {
        $this->assertNotPaused($convocation);

        $today = InstitutionDate::todayOn();
        $startsAt = $this->startsAt($convocation);
        $startsOn = InstitutionDate::dayOf($startsAt);

        if ($today < $startsOn) {
            throw ValidationException::withMessages([
                'deadline' => 'La convocatoria todavía no inicia; podrá enviar a partir del '
                    .CarbonImmutable::parse($startsOn, 'UTC')->translatedFormat('d/m/Y').'.',
            ]);
        }

        $deadline = $this->draftDeadline($convocation);
        $deadlineOn = InstitutionDate::dayOf($deadline);

        if ($today > $deadlineOn) {
            throw ValidationException::withMessages([
                'deadline' => 'El plazo de entrega venció el '
                    .CarbonImmutable::parse($deadlineOn, 'UTC')->translatedFormat('d/m/Y')
                    .'. Solicite una prórroga a la coordinación de su carrera.',
            ]);
        }
    }

    /** @throws ValidationException */
    public function assertNotPaused(Convocation $convocation): void
    {
        if ($convocation->estado === Convocation::STATE_PAUSED) {
            throw ValidationException::withMessages([
                'deadline' => 'La convocatoria está en pausa por decisión de la coordinación de su carrera. Podrá enviar cuando se reanude.',
            ]);
        }

        $processState = $convocation->relationLoaded('process')
            ? $convocation->process->estado
            : $convocation->process()->value('estado');

        if ($processState === SyllabusProcess::STATE_PAUSED) {
            throw ValidationException::withMessages([
                'deadline' => 'El proceso institucional está en pausa. Podrá enviar cuando Administración lo reanude.',
            ]);
        }
        if ($processState === SyllabusProcess::STATE_CLOSED) {
            throw ValidationException::withMessages([
                'deadline' => 'El proceso institucional ya se cerró y no admite más envíos.',
            ]);
        }
    }
}
