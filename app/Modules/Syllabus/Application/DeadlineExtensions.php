<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;

final class DeadlineExtensions
{
    public static function limitOn(SyllabusProcess $process): string
    {
        return $process->academicPeriod->fecha_fin->toDateString();
    }

    public static function blockReason(SyllabusProcess $process): ?string
    {
        if ($process->cantidad_prorrogas >= SyllabusProcess::MAX_EXTENSIONS) {
            return 'Ya se usaron las '.SyllabusProcess::MAX_EXTENSIONS.' prórrogas de esta convocatoria.';
        }
        $limitOn = self::limitOn($process);
        if (InstitutionDate::dateOf($process->entrega_en) >= $limitOn) {
            return 'El plazo ya llega al último día de clases ('.self::display($limitOn).').';
        }
        if ($limitOn < InstitutionDate::todayOn()) {
            return 'Las clases del período terminaron el '.self::display($limitOn).'.';
        }

        return null;
    }

    public static function pastLimitMessage(SyllabusProcess $process): string
    {
        return 'La nueva fecha no puede pasar del último día de clases ('.self::display(self::limitOn($process)).').';
    }

    private static function display(string $day): string
    {
        return CarbonImmutable::parse($day, 'UTC')->format('d/m/Y');
    }
}
