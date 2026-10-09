<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Documents\Infrastructure\Rendering\HistoricalFieldDocument;

final class SnapshotDocuments
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function present(array $snapshot): array
    {
        $variables = TemplateVariables::resolve($snapshot);
        foreach ($snapshot['sections'] ?? [] as $index => $section) {
            $fields = $section['fields'] ?? array_merge(...array_map(fn (array $block): array => $block['fields'] ?? [], $section['blocks'] ?? []));
            $snapshot['sections'][$index]['orientation'] = $section['orientation'] ?? 'portrait';
            $snapshot['sections'][$index]['fields'] = array_values(array_map(function (array $field) use ($variables): array {
                $document = HistoricalFieldDocument::of($field);

                return [
                    'id' => $field['definition_id'] ?? $field['id'] ?? $field['key'],
                    'definition_id' => $field['definition_id'] ?? null,
                    'key' => $field['key'],
                    'label' => $field['label'] ?? '',
                    'help' => null,
                    'quien_llena' => $field['quien_llena'] ?? match ($field['type'] ?? null) {
                        'automatico', 'fijo' => 'institucional',
                        'ficha' => 'coordinacion',
                        default => 'docente',
                    },
                    'teacher_editable' => false,
                    'required' => false,
                    'value' => $document,
                    'document' => FieldDocument::resolve($document, $variables),
                ];
            }, $fields));
        }

        return $snapshot;
    }
}
