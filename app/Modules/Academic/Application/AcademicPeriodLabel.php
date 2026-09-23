<?php

namespace App\Modules\Academic\Application;

use Carbon\CarbonImmutable;

final class AcademicPeriodLabel
{
    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre',
    ];

    public function make(string $startsOn, string $endsOn): string
    {
        $start = CarbonImmutable::parse($startsOn);
        $end = CarbonImmutable::parse($endsOn);
        $year = $start->year === $end->year
            ? (string) $start->year
            : "{$start->year}-{$end->year}";

        return sprintf(
            '%s-%s %s',
            self::MONTHS[$start->month],
            self::MONTHS[$end->month],
            $year,
        );
    }
}
