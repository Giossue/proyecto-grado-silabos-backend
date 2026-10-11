<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use RuntimeException;

final class OfficialTemplate
{
    public const PATH = 'resources/templates/silabo-oficial.json';

    private const PRESETS = [
        'identificacion' => ['field' => 'asignatura', 'label' => 'Identificación institucional'],
        'planificacion' => ['field' => 'unidades', 'label' => 'Distribución y planificación de las unidades curriculares'],
    ];

    /**
     * @return list<array{key: string, title: string, orientation?: string, fields: list<array{key: string, label?: string|null, help?: string|null, quien_llena: string, content: array<string, mixed>}>}>
     */
    public static function sections(): array
    {
        $raw = file_get_contents(base_path(self::PATH));
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['sections'] ?? null)) {
            throw new RuntimeException('La plantilla oficial ('.self::PATH.') no es un JSON válido.');
        }

        $sections = [];
        foreach ($decoded['sections'] as $section) {
            foreach ($section['fields'] ?? [] as $index => $field) {
                $section['fields'][$index]['content'] = is_array($field['content']) && ! array_is_list($field['content'])
                    ? $field['content']
                    : DocumentHtml::fromHtml($field['content']);
            }
            $sections[] = $section;
        }

        /** @var list<array{key: string, title: string, orientation?: string, fields: list<array{key: string, label?: string|null, help?: string|null, quien_llena: string, content: array<string, mixed>}>}> $sections */
        return $sections;
    }

    /**
     * @return list<string>
     */
    public static function sectionKeys(): array
    {
        return array_map(fn (array $section): string => $section['key'], self::sections());
    }

    /**
     * @return list<array{key: string, field_key: string, label: string, quien_llena: string, content: array<string, mixed>}>
     */
    public static function presets(): array
    {
        $fields = [];
        foreach (self::sections() as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        $presets = [];
        foreach (self::PRESETS as $key => ['field' => $fieldKey, 'label' => $label]) {
            $field = $fields[$fieldKey] ?? throw new RuntimeException("La plantilla oficial no tiene el campo «{$fieldKey}» que usa la plantilla lista «{$key}».");
            $presets[] = ['key' => $key, 'field_key' => $fieldKey, 'label' => $label, 'quien_llena' => $field['quien_llena'], 'content' => FieldDocument::normalize($field['content'])];
        }

        return $presets;
    }
}
