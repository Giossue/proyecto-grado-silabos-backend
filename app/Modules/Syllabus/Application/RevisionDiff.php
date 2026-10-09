<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;

class RevisionDiff
{
    public function __construct(private readonly CanonicalHasher $hasher) {}

    /**
     * @return array{before_revision: int, after_revision: int, changed_fields: int, changes: list<array<string, mixed>>}
     */
    public function compare(SyllabusRevision $before, SyllabusRevision $after): array
    {
        $beforeFields = $this->flatten($before->fotografia);
        $afterFields = $this->flatten($after->fotografia);
        $keys = array_values(array_unique([...array_keys($beforeFields), ...array_keys($afterFields)]));
        sort($keys, SORT_STRING);
        $changes = [];

        foreach ($keys as $key) {
            $old = $beforeFields[$key] ?? null;
            $new = $afterFields[$key] ?? null;
            if ($this->hasher->hash($old) === $this->hasher->hash($new)) {
                continue;
            }
            $changes[] = [
                'section_key' => $new['section_key'] ?? $old['section_key'] ?? '',
                'section_title' => $new['section_title'] ?? $old['section_title'] ?? '',
                'field_key' => $new['field_key'] ?? $old['field_key'] ?? '',
                'label' => $new['label'] ?? $old['label'] ?? '',
                'quien_llena' => $new['quien_llena'] ?? $old['quien_llena'] ?? 'docente',
                'change' => $old === null ? 'added' : ($new === null ? 'removed' : 'modified'),
                'before' => $old === null ? null : ['value' => $old['value']],
                'after' => $new === null ? null : ['value' => $new['value']],
            ];
        }

        return [
            'before_revision' => $before->numero_revision,
            'after_revision' => $after->numero_revision,
            'changed_fields' => count($changes),
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function flatten(array $snapshot): array
    {
        $fields = [];
        foreach ($this->arrayList($snapshot['sections'] ?? null) as $section) {
            $sectionKey = is_string($section['key'] ?? null) ? $section['key'] : '';
            $sectionTitle = is_string($section['title'] ?? null) ? $section['title'] : $sectionKey;
            foreach (isset($section['fields']) ? [$section] : $this->arrayList($section['blocks'] ?? null) as $block) {
                foreach ($this->arrayList($block['fields'] ?? null) as $field) {
                    $fieldKey = is_string($field['key'] ?? null) ? $field['key'] : '';
                    if ($sectionKey === '' || $fieldKey === '') {
                        continue;
                    }
                    $fields["{$sectionKey}.{$fieldKey}"] = [
                        'section_key' => $sectionKey,
                        'section_title' => $sectionTitle,
                        'field_key' => $fieldKey,
                        'label' => is_string($field['label'] ?? null) ? $field['label'] : $fieldKey,
                        'quien_llena' => $field['quien_llena'] ?? 'docente',
                        'value' => $field['value'] ?? null,
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
