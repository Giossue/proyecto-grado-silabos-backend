<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Support\InstitutionDate;
use Illuminate\Validation\ValidationException;

final class AcademicPeriodPlanning
{
    public const UPCOMING = 'proximo';

    public const CURRENT = 'en_curso';

    public const FINISHED = 'finalizado';

    /** @return self::UPCOMING|self::CURRENT|self::FINISHED */
    public function status(AcademicPeriod $period): string
    {
        $today = InstitutionDate::todayOn();

        if (InstitutionDate::dateOf($period->fecha_fin) < $today) {
            return self::FINISHED;
        }

        return InstitutionDate::dateOf($period->fecha_inicio) > $today ? self::UPCOMING : self::CURRENT;
    }

    public function label(AcademicPeriod $period): string
    {
        return match ($this->status($period)) {
            self::UPCOMING => 'Próximo',
            self::CURRENT => 'En curso',
            self::FINISHED => 'Finalizado',
        };
    }

    public function mayPlan(AcademicPeriod $period): bool
    {
        return $this->status($period) !== self::FINISHED;
    }

    public function assertMayPlan(AcademicPeriod $period, string $field = 'period_id'): void
    {
        if ($this->status($period) === self::FINISHED) {
            throw ValidationException::withMessages([
                $field => 'El período finalizó y se conserva únicamente para consulta.',
            ]);
        }

    }
}
