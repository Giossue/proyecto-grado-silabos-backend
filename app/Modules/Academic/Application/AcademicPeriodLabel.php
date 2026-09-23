<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
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

    public function make(string $startsOn, string $endsOn, ?string $exceptId = null): string
    {
        $start = CarbonImmutable::parse($startsOn);
        $end = CarbonImmutable::parse($endsOn);
        $ordinal = $this->ordinal($start, $exceptId);
        $year = $start->year === $end->year
            ? (string) $start->year
            : "{$start->year}-{$end->year}";

        do {
            $label = sprintf(
                '%s-%s %s %s',
                self::MONTHS[$start->month],
                self::MONTHS[$end->month],
                $this->roman($ordinal),
                $year,
            );
            $ordinal++;
        } while ($this->labelInUse($label, $exceptId));

        return $label;
    }

    private function ordinal(CarbonImmutable $start, ?string $exceptId): int
    {
        return AcademicPeriod::query()
            ->whereYear('fecha_inicio_periodo', $start->year)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereDate('fecha_inicio_periodo', '<=', $start->toDateString())
            ->count() + 1;
    }

    private function labelInUse(string $label, ?string $exceptId): bool
    {
        return AcademicPeriod::query()
            ->where('codigo_periodo_academico', $label)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    private function roman(int $number): string
    {
        $values = [
            1000 => 'M',
            900 => 'CM',
            500 => 'D',
            400 => 'CD',
            100 => 'C',
            90 => 'XC',
            50 => 'L',
            40 => 'XL',
            10 => 'X',
            9 => 'IX',
            5 => 'V',
            4 => 'IV',
            1 => 'I',
        ];
        $roman = '';
        foreach ($values as $value => $symbol) {
            while ($number >= $value) {
                $roman .= $symbol;
                $number -= $value;
            }
        }

        return $roman;
    }
}
