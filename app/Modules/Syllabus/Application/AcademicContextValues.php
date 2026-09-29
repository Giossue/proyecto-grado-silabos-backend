<?php

namespace App\Modules\Syllabus\Application;

final class AcademicContextValues
{
    /** @param array<string, mixed> $context */
    public static function scheduledSubject(array $context, string $key): mixed
    {
        return data_get($context, "scheduled_subject.{$key}")
            ?? data_get($context, "offering.{$key}");
    }
}
