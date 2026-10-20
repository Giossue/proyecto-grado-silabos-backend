<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateContext;

final class BibliographySections
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public static function of(array $snapshot): array
    {
        $sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];

        $keys = TemplateContext::fromSnapshot($snapshot)->librarySections();

        return array_values(array_filter(
            $sections,
            fn (mixed $section): bool => is_array($section) && in_array($section['key'] ?? null, $keys, true),
        ));
    }
}
