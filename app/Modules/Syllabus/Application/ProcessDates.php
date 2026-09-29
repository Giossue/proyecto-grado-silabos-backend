<?php

namespace App\Modules\Syllabus\Application;

use Illuminate\Support\Carbon;

final class ProcessDates
{
    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    public static function startOfDay(mixed $value): mixed
    {
        return is_string($value) && preg_match(self::DATE_ONLY, $value) === 1
            ? Carbon::parse($value)->startOfDay()->toIso8601String()
            : $value;
    }

    public static function endOfDay(mixed $value): mixed
    {
        return is_string($value) && preg_match(self::DATE_ONLY, $value) === 1
            ? Carbon::parse($value)->endOfDay()->toIso8601String()
            : $value;
    }
}
