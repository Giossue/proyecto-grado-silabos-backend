<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Reopening;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;

final class BibliographyCorrection
{
    public const SECTION_KEY = 'bibliografia';

    public static function pendingReopening(Syllabus $syllabus): ?Reopening
    {
        if ($syllabus->estado !== 'correccion_solicitada') {
            return null;
        }

        return $syllabus->reopenings()
            ->whereNotIn('reaperturas.id', SyllabusRevision::query()->whereNotNull('reapertura_id')->select('reapertura_id'))
            ->latest('reabierto_en')
            ->first();
    }

    public static function requested(Syllabus $syllabus): bool
    {
        return self::pendingReopening($syllabus)?->origen_reapertura === Reopening::ORIGIN_LIBRARY;
    }

    public static function includes(FieldDefinition $field): bool
    {
        return $field->block?->section->clave === self::SECTION_KEY;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function replaceValues(array $snapshot, array $values): array
    {
        $sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];
        foreach ($sections as $s => $section) {
            if (! is_array($section) || ($section['key'] ?? null) !== self::SECTION_KEY) {
                continue;
            }
            foreach (is_array($section['blocks'] ?? null) ? $section['blocks'] : [] as $b => $block) {
                foreach (is_array($block['fields'] ?? null) ? $block['fields'] : [] as $f => $field) {
                    $definitionId = is_array($field) ? ($field['definition_id'] ?? null) : null;
                    if (is_string($definitionId) && array_key_exists($definitionId, $values)) {
                        $sections[$s]['blocks'][$b]['fields'][$f]['value'] = $values[$definitionId];
                    }
                }
            }
        }
        $snapshot['sections'] = $sections;

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public static function sections(array $snapshot): array
    {
        $sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];

        return array_values(array_filter(
            $sections,
            fn (mixed $section): bool => is_array($section) && ($section['key'] ?? null) === self::SECTION_KEY,
        ));
    }
}
