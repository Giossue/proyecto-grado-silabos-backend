<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FieldDocument;
use RuntimeException;

final class OfficialTemplate
{
    public const PATH = 'resources/templates/silabo-oficial.json';

    private const PRESETS = ['identificacion' => 'asignatura', 'planificacion' => 'unidades', 'firmas' => 'firmas'];

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

        /** @var list<array{key: string, title: string, orientation?: string, fields: list<array{key: string, label?: string|null, help?: string|null, quien_llena: string, content: array<string, mixed>}>}> $sections */
        $sections = $decoded['sections'];

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
     * @return list<array{key: string, label: string, quien_llena: string, content: array<string, mixed>}>
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
        foreach (self::PRESETS as $key => $fieldKey) {
            $field = $fields[$fieldKey] ?? throw new RuntimeException("La plantilla oficial no tiene el campo «{$fieldKey}» que usa la plantilla lista «{$key}».");
            $presets[] = ['key' => $key, 'label' => (string) ($field['label'] ?? ''), 'quien_llena' => $field['quien_llena'], 'content' => FieldDocument::normalize($field['content'])];
        }

        return $presets;
    }
}
