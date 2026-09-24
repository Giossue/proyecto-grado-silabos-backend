<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Validation\ValidationException;

/**
 * Qué se congela mientras el trabajo docente avanza.
 *
 * Con el proceso institucional abierto, la plantilla no cambia: los docentes están
 * llenando ese formato. Los catálogos institucionales y las cuentas siguen operativos.
 * Con una convocatoria abierta, la malla y las fuentes de esa carrera no cambian: los
 * sílabos se apoyan en ellas. Para editarlas la coordinación pausa su convocatoria; la
 * pausa institucional del proceso no basta. Los registros institucionales (período)
 * sí se liberan con la pausa del proceso.
 *
 * Las razones se devuelven como texto para que la interfaz explique el bloqueo con las
 * mismas palabras con las que el servidor lo rechaza.
 */
class ProcessLocks
{
    private const PROCESS_LOCK_REASON = 'Proceso activo';

    private const CONVOCATION_LOCK_REASON = 'Convocatoria activa';

    public function templateLockReason(): ?string
    {
        $process = SyllabusProcess::query()
            ->where('estado', SyllabusProcess::STATE_OPEN)
            ->with('academicPeriod:id,codigo')
            ->first(['id', 'periodo_academico_id']);

        if ($process === null) {
            return null;
        }

        return self::PROCESS_LOCK_REASON;
    }

    public function assertTemplateEditable(): void
    {
        $reason = $this->templateLockReason();

        if ($reason !== null) {
            throw ValidationException::withMessages(['process' => $reason]);
        }
    }

    public function careerLockReason(?string $careerId): ?string
    {
        if ($careerId === null) {
            return null;
        }

        $convocation = Convocation::query()
            ->where('carrera_id', $careerId)
            ->lockingCareerData()
            ->with(['career:id,nombre', 'process.academicPeriod:id,codigo'])
            ->first(['id', 'carrera_id', 'proceso_id']);

        if ($convocation === null) {
            return null;
        }

        return self::CONVOCATION_LOCK_REASON;
    }

    public function assertCareerEditable(?string $careerId): void
    {
        $reason = $this->careerLockReason($careerId);

        if ($reason !== null) {
            throw ValidationException::withMessages(['process' => $reason]);
        }
    }

    /** @param list<string> $careerIds */
    public function assertInstitutionalRecordEditable(array $careerIds = [], ?string $periodId = null): void
    {
        if ($careerIds === [] && $periodId === null) {
            return;
        }

        $running = Convocation::query()
            ->running()
            ->when($careerIds !== [], fn ($query) => $query->whereIn('carrera_id', $careerIds))
            ->when($periodId !== null, fn ($query) => $query->whereHas(
                'process',
                fn ($process) => $process->where('periodo_academico_id', $periodId),
            ))
            ->exists();

        if ($running) {
            throw ValidationException::withMessages(['process' => self::CONVOCATION_LOCK_REASON]);
        }
    }
}
