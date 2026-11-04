<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;

final class DeadlineExtensions
{
    public const ANOTHER_IN_PROGRESS = 'Hay otra convocatoria en curso; finalícela o elimínela antes de reabrir esta';

    public const TEMPLATE_NOT_READY = 'La plantilla no está lista para usarse. Complétela; «Antes de empezar» indica qué falta.';

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
        if ($process->estado === SyllabusProcess::STATE_CLOSED
            && SyllabusProcess::query()->inProgress()->whereKeyNot($process->id)->exists()) {
            return self::ANOTHER_IN_PROGRESS;
        }
        if ($process->estado === SyllabusProcess::STATE_CLOSED
            && app(TemplateStructureValidator::class)->problem(SyllabusTemplate::query()->first()) !== null) {
            return self::TEMPLATE_NOT_READY;
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
