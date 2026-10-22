<?php

namespace App\Modules\Syllabus\Application;

use App\Support\InstitutionDate;

final class ProcessDates
{
    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    public static function startOfDay(mixed $value): mixed
    {
        return self::day($value, true);
    }

    public static function endOfDay(mixed $value): mixed
    {
        return self::day($value, false);
    }

    private static function day(mixed $value, bool $start): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }
        if (preg_match(self::DATE_ONLY, $value) === 1) {
            $day = $value;
        } else {
            $day = InstitutionDate::dayOfValue($value);
            if ($day === null) {
                return $value;
            }
        }
        $moment = $start
            ? InstitutionDate::startOfDay($day)
            : InstitutionDate::endOfDay($day);

        return $moment->setTimezone('UTC')->toIso8601String();
    }
}
