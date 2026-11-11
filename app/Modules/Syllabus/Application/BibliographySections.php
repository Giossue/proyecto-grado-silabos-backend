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

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{section: mixed, field: mixed, value: mixed}>
     */
    public static function answers(array $snapshot): array
    {
        $answers = [];
        foreach (self::of($snapshot) as $section) {
            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (is_array($field) && ($field['quien_llena'] ?? null) !== 'institucional') {
                    $answers[] = ['section' => $section['key'] ?? null, 'field' => $field['key'] ?? null, 'value' => $field['value'] ?? null];
                }
            }
        }

        return $answers;
    }
}
