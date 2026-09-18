<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Validation\ValidationException;

/**
 * Qué se congela mientras el trabajo docente avanza.
 *
 * Con el proceso institucional abierto, la plantilla no cambia: los docentes están
 * llenando ese formato. Con una convocatoria en curso, la malla y las fuentes de esa
 * carrera no cambian: los sílabos se apoyan en ellas. Para editar, primero se pausa —
 * Administración el proceso, Coordinación su convocatoria— y el bloqueo se levanta solo
 * en ese alcance.
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

    /**
     * Los catálogos institucionales también quedan congelados mientras la universidad
     * trabaja con un proceso abierto. Sus cambios no deben aparecer a mitad del ciclo.
     * Al pausar el proceso, Administración vuelve a tener el espacio de corrección.
     */
    public function assertInstitutionalStructureEditable(): void
    {
        $reason = $this->institutionalStructureLockReason();

        if ($reason !== null) {
            throw ValidationException::withMessages(['process' => $reason]);
        }
    }

    public function institutionalStructureLockReason(): ?string
    {
        $process = SyllabusProcess::query()
            ->where('estado', SyllabusProcess::STATE_OPEN)
            ->with('academicPeriod:id,codigo')
            ->first(['id', 'periodo_academico_id']);

        return $process === null ? null : self::PROCESS_LOCK_REASON;
    }

    public function careerLockReason(?string $careerId): ?string
    {
        if ($careerId === null) {
            return null;
        }

        $convocation = Convocation::query()
            ->where('carrera_id', $careerId)
            ->running()
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
}
