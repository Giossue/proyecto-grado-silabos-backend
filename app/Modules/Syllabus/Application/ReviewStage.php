<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;

/**
 * Etapa de la revisión vigente (I-173). Se deriva de los registros, sin columna de estado:
 * biblioteca → coordinación → decanato.
 */
final class ReviewStage
{
    public const LIBRARY = 'biblioteca';

    public const COORDINATION = 'coordinacion';

    public const DEANERY = 'decanato';

    public const ORDER = [self::LIBRARY, self::COORDINATION, self::DEANERY];

    /** Etapa en la que está una revisión; null si ya fue aprobada. */
    public static function ofRevision(SyllabusRevision $revision): ?string
    {
        if (Approval::query()->where('revision_silabo_id', $revision->id)->exists()) {
            return null;
        }
        if (CoordinationReview::query()->where('revision_silabo_id', $revision->id)->exists()) {
            return self::DEANERY;
        }
        $verified = LibrarySubmission::query()
            ->where('revision_silabo_id', $revision->id)
            ->whereNotNull('verificado_en')
            ->exists();

        return $verified ? self::COORDINATION : self::LIBRARY;
    }

    /** Etapa del sílabo mientras está en revisión; null en cualquier otro estado. */
    public static function of(Syllabus $syllabus): ?string
    {
        if ($syllabus->estado !== 'en_revision') {
            return null;
        }
        $latest = SyllabusRevision::query()
            ->where('silabo_id', $syllabus->id)
            ->orderByDesc('numero_revision')
            ->first();

        return $latest === null ? null : self::ofRevision($latest);
    }

    /** Etapa que decide cada rol revisor. */
    public static function forRole(string $roleCode): ?string
    {
        return match ($roleCode) {
            RoleCode::Librarian->value => self::LIBRARY,
            RoleCode::Coordinator->value => self::COORDINATION,
            RoleCode::Dean->value => self::DEANERY,
            default => null,
        };
    }

    public static function label(string $stage): string
    {
        return match ($stage) {
            self::LIBRARY => 'En biblioteca',
            self::COORDINATION => 'En coordinación',
            self::DEANERY => 'En decanato',
            default => $stage,
        };
    }
}
