<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Día civil de la institución.
 *
 * El calendario de negocio lo decide la institución (`DISPLAY_TIMEZONE`), no el
 * servidor en UTC ni el navegador. Un día civil es una fecha sin hora; sus límites
 * (medianoche y fin del día) se guardan como instantes en esa zona.
 */
final class InstitutionDate
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'America/Guayaquil');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function todayOn(): string
    {
        return self::today()->toDateString();
    }

    public static function dayOf(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone(self::timezone())->toDateString();
    }

    public static function dayOfValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return self::dayOf($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }
        try {
            return self::dayOf(CarbonImmutable::parse($value));
        } catch (Throwable) {
            return null;
        }
    }

    public static function startOfDay(string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day, self::timezone())->startOfDay();
    }

    public static function endOfDay(string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day, self::timezone())->endOfDay();
    }

    public static function daysBetween(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from, self::timezone())->diffInDays(CarbonImmutable::parse($to, self::timezone()), false);
    }
}
