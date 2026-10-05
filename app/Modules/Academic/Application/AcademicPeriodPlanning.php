<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class AcademicPeriodPlanning
{
    public const UPCOMING = 'proximo';

    public const CURRENT = 'en_curso';

    public const FINISHED = 'finalizado';

    /** @return self::UPCOMING|self::CURRENT|self::FINISHED */
    public function status(AcademicPeriod $period): string
    {
        $today = CarbonImmutable::today((string) config('app.display_timezone', 'America/Guayaquil'));

        if ($period->fecha_fin->lt($today)) {
            return self::FINISHED;
        }

        return $period->fecha_inicio->gt($today) ? self::UPCOMING : self::CURRENT;
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

    public function assertParallelMayChange(Parallel $parallel, string $field = 'record'): void
    {
        $parallel->loadMissing('academicPeriod');
        $this->assertMayPlan($parallel->academicPeriod, $field);
    }

    public function assertTeacherAssignmentMayChange(TeacherAssignment $assignment, string $field = 'record'): void
    {
        $assignment->loadMissing('parallel.academicPeriod');
        $this->assertMayPlan($assignment->parallel->academicPeriod, $field);
    }
}
