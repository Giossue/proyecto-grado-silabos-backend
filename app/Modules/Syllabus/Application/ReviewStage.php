<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Library\Infrastructure\Persistence\Models\LibrarySubmission;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;

final class ReviewStage
{
    public const LIBRARY = 'biblioteca';

    public const COORDINATION = 'coordinacion';

    public const ORDER = [self::LIBRARY, self::COORDINATION];

    public static function ofRevision(SyllabusRevision $revision): ?string
    {
        if (Approval::query()->where('revision_silabo_id', $revision->id)->exists()) {
            return null;
        }
        $verified = LibrarySubmission::query()
            ->where('revision_silabo_id', $revision->id)
            ->whereNotNull('verificado_en')
            ->exists();

        return $verified ? self::COORDINATION : self::LIBRARY;
    }

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

    public static function forRole(string $roleCode): ?string
    {
        return match ($roleCode) {
            RoleCode::Librarian->value => self::LIBRARY,
            RoleCode::Coordinator->value => self::COORDINATION,
            default => null,
        };
    }

    public static function label(string $stage): string
    {
        return match ($stage) {
            self::LIBRARY => 'En biblioteca',
            self::COORDINATION => 'En coordinación',
            default => $stage,
        };
    }
}
