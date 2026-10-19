<?php

namespace App\Modules\Syllabus\Application;

final class BibliographySections
{
    public const SECTION_KEY = 'bibliografia';

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public static function of(array $snapshot): array
    {
        $sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];

        return array_values(array_filter(
            $sections,
            fn (mixed $section): bool => is_array($section) && ($section['key'] ?? null) === self::SECTION_KEY,
        ));
    }
}
