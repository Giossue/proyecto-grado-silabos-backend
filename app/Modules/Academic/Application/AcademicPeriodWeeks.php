<?php

namespace App\Modules\Academic\Application;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class AcademicPeriodWeeks
{
    public function calculate(string $startsOn, string $endsOn): int
    {
        $start = CarbonImmutable::parse($startsOn);
        $end = CarbonImmutable::parse($endsOn);

        if ($end->lt($start)) {
            throw new InvalidArgumentException('La fecha de fin no puede ser anterior a la fecha de inicio.');
        }

        $firstMonday = $start->startOfWeek(CarbonImmutable::MONDAY);
        $lastMonday = $end->startOfWeek(CarbonImmutable::MONDAY);

        return (int) $firstMonday->diffInWeeks($lastMonday) + 1;
    }
}
