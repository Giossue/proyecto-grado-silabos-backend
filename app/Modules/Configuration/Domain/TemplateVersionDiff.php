<?php

namespace App\Modules\Configuration\Domain;

final class TemplateVersionDiff
{
    private const IGNORED_ROOT_KEYS = ['$schema', 'version', 'document', 'sections'];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $old = self::sections($before);
        $new = self::sections($after);

        return [
            ...self::sectionChanges($old, $new),
            ...self::fieldChanges($old, $new),
            ...self::settingChanges($before, $after),
        ];
    }

    /**
     * @param  array<string, array{title: string, orientation: string, fields: array<string, array{label: string, editable: bool, content: mixed}>}>  $old
     * @param  array<string, array{title: string, orientation: string, fields: array<string, array{label: string, editable: bool, content: mixed}>}>  $new
     * @return list<string>
     */
    private static function sectionChanges(array $old, array $new): array
    {
        $changes = [];
        foreach (array_diff_key($new, $old) as $section) {
            $changes[] = "Sección agregada: «{$section['title']}»";
        }
        foreach (array_diff_key($old, $new) as $section) {
            $changes[] = "Sección quitada: «{$section['title']}»";
        }
        $kept = array_intersect_key($new, $old);
        foreach ($kept as $key => $section) {
            if ($old[$key]['title'] !== $section['title']) {
                $changes[] = "Sección renombrada: «{$old[$key]['title']}» → «{$section['title']}»";
            }
        }
        foreach ($kept as $key => $section) {
            if ($old[$key]['orientation'] !== $section['orientation']) {
                $changes[] = "Orientación cambiada: «{$section['title']}»";
            }
        }
        if (array_keys(array_intersect_key($old, $new)) !== array_keys($kept)) {
            $changes[] = 'Secciones en otro orden';
        }

        return $changes;
    }

    /**
     * @param  array<string, array{title: string, orientation: string, fields: array<string, array{label: string, editable: bool, content: mixed}>}>  $old
     * @param  array<string, array{title: string, orientation: string, fields: array<string, array{label: string, editable: bool, content: mixed}>}>  $new
     * @return list<string>
     */
    private static function fieldChanges(array $old, array $new): array
    {
        $before = self::fieldIndex($old);
        $after = self::fieldIndex($new);
        $changes = ['added' => [], 'removed' => [], 'renamed' => [], 'moved' => [], 'order' => [], 'editable' => [], 'content' => []];
        foreach (array_diff_key($after, $before) as $field) {
            $changes['added'][] = "Campo agregado: «{$field['label']}» en «{$new[$field['section']]['title']}»";
        }
        foreach (array_diff_key($before, $after) as $field) {
            $changes['removed'][] = "Campo quitado: «{$field['label']}»";
        }
        foreach (array_intersect_key($after, $before) as $key => $field) {
            $previous = $before[$key];
            if ($previous['label'] !== $field['label']) {
                $changes['renamed'][] = "Campo renombrado: «{$previous['label']}» → «{$field['label']}»";
            }
            if ($previous['section'] !== $field['section']) {
                $changes['moved'][] = "Campo movido: «{$field['label']}», de «{$old[$previous['section']]['title']}» a «{$new[$field['section']]['title']}»";
            }
            if ($previous['editable'] !== $field['editable']) {
                $changes['editable'][] = "Campo «{$field['label']}»: ".($field['editable'] ? 'ahora se puede editar' : 'ahora no se puede editar');
            }
            if ($previous['content'] !== $field['content']) {
                $changes['content'][] = "Contenido cambiado: «{$field['label']}»";
            }
        }
        foreach (array_intersect_key($new, $old) as $key => $section) {
            $stayed = fn (array $fields, string $section): array => array_keys(array_filter($fields, fn (array $field): bool => $field['section'] === $section));
            $previousOrder = array_values(array_intersect($stayed($before, $key), $stayed($after, $key)));
            $currentOrder = array_values(array_intersect($stayed($after, $key), $stayed($before, $key)));
            if ($previousOrder !== $currentOrder) {
                $changes['order'][] = "Campos en otro orden en «{$section['title']}»";
            }
        }

        return [...$changes['added'], ...$changes['removed'], ...$changes['renamed'], ...$changes['moved'], ...$changes['order'], ...$changes['editable'], ...$changes['content']];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private static function settingChanges(array $before, array $after): array
    {
        $changes = [];
        $oldDocument = is_array($before['document'] ?? null) ? $before['document'] : [];
        $newDocument = is_array($after['document'] ?? null) ? $after['document'] : [];
        if (self::title($oldDocument) !== self::title($newDocument)) {
            $changes[] = 'Título del documento cambiado';
        }
        unset($oldDocument['title']['text'], $newDocument['title']['text']);
        $oldRest = array_diff_key($before, array_flip(self::IGNORED_ROOT_KEYS));
        $newRest = array_diff_key($after, array_flip(self::IGNORED_ROOT_KEYS));
        if ($oldDocument != $newDocument || $oldRest != $newRest) {
            $changes[] = 'Otros ajustes de la plantilla cambiados';
        }

        return $changes;
    }

    /** @param  array<mixed>  $document */
    private static function title(array $document): string
    {
        $title = $document['title'] ?? null;

        return is_array($title) && is_string($title['text'] ?? null) ? $title['text'] : '';
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, array{title: string, orientation: string, fields: array<string, array{label: string, editable: bool, content: mixed}>}>
     */
    private static function sections(array $template): array
    {
        $sections = [];
        foreach (is_array($template['sections'] ?? null) ? $template['sections'] : [] as $section) {
            if (! is_array($section) || ! is_string($section['key'] ?? null)) {
                continue;
            }
            $fields = [];
            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                    continue;
                }
                $label = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? trim($field['label']) : $field['key'];
                $fields[$field['key']] = ['label' => $label, 'editable' => ($field['quien_llena'] ?? null) !== 'institucional', 'content' => $field['content'] ?? null];
            }
            $sections[$section['key']] = [
                'title' => is_string($section['title'] ?? null) ? $section['title'] : $section['key'],
                'orientation' => is_string($section['orientation'] ?? null) ? $section['orientation'] : 'portrait',
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, array{title: string, orientation: string, fields: array<string, array{label: string, editable: bool, content: mixed}>}>  $sections
     * @return array<string, array{label: string, editable: bool, content: mixed, section: string}>
     */
    private static function fieldIndex(array $sections): array
    {
        $index = [];
        foreach ($sections as $key => $section) {
            foreach ($section['fields'] as $fieldKey => $field) {
                $index[$fieldKey] = [...$field, 'section' => (string) $key];
            }
        }

        return $index;
    }
}
