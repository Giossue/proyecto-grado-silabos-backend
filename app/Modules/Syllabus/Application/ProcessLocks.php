<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Illuminate\Validation\ValidationException;

class ProcessLocks
{
    private const PROCESS_LOCK_REASON = 'Proceso activo';

    private const CONVOCATION_LOCK_REASON = 'Convocatoria activa';

    private const CLOSED_CONVOCATION_LOCK_REASON = 'Convocatoria cerrada';

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

    /** @return array{scope: string, period: string|null, institutional_pause: bool, delivery_on: string|null}|null */
    public function templateLock(): ?array
    {
        $process = SyllabusProcess::query()
            ->where('estado', SyllabusProcess::STATE_OPEN)
            ->with('academicPeriod:id,codigo')
            ->first();

        return $process === null ? null : [
            'scope' => 'process',
            'period' => $process->academicPeriod?->codigo,
            'institutional_pause' => false,
            'delivery_on' => InstitutionDate::dateOf($process->entrega_en),
        ];
    }

    /** @return array{scope: string, period: string|null, institutional_pause: bool, delivery_on: string|null}|null */
    public function careerLock(?string $careerId): ?array
    {
        if ($careerId === null) {
            return null;
        }

        $convocation = Convocation::query()
            ->where('carrera_id', $careerId)
            ->lockingCareerData()
            ->with('process.academicPeriod:id,codigo')
            ->first();

        return $convocation === null ? null : [
            'scope' => 'convocation',
            'period' => $convocation->process->academicPeriod?->codigo,
            'institutional_pause' => $convocation->process->estado === SyllabusProcess::STATE_PAUSED,
            'delivery_on' => InstitutionDate::dateOf($convocation->process->entrega_en),
        ];
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
            ->with(['career:id,nombre,codigo_carrera', 'process.academicPeriod:id,codigo'])
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

    /** @return array<string, string> período => motivo */
    public function lockedPeriodReasons(): array
    {
        $processes = SyllabusProcess::query()
            ->get(['periodo_academico_id', 'estado']);

        return $processes->mapWithKeys(fn (SyllabusProcess $process): array => [
            $process->periodo_academico_id => $process->estado === SyllabusProcess::STATE_CLOSED
                ? self::CLOSED_CONVOCATION_LOCK_REASON
                : self::CONVOCATION_LOCK_REASON,
        ])->all();
    }

    /**
     * Carreras con una convocatoria abierta: sus datos y los de su facultad no se editan hasta pausarla.
     *
     * @return list<string>
     */
    public function careersWithRunningConvocation(): array
    {
        return array_values(array_unique(Convocation::query()->running()->get(['carrera_id'])->map(fn (Convocation $convocation): string => (string) $convocation->carrera_id)->all()));
    }

    public function institutionalRecordLockReason(): string
    {
        return self::CONVOCATION_LOCK_REASON;
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
