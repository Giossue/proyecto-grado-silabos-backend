<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\TemplateDefinition;
use RuntimeException;

final class OfficialTemplate
{
    public const PATH = 'resources/templates/silabo-oficial.json';

    private static ?TemplateDefinition $definition = null;

    public static function definition(): TemplateDefinition
    {
        return self::$definition ??= TemplateDefinition::define(
            array_intersect_key(self::decoded(), array_flip(TemplateDefinition::ROOT_KEYS)),
            self::sections(),
        );
    }

    /**
     * @return list<array{key: string, title: string, orientation?: string, fields: list<array{key: string, label?: string|null, quien_llena: string, content: array<string, mixed>}>}>
     */
    public static function sections(): array
    {
        $sections = [];
        foreach (self::decoded()['sections'] as $section) {
            foreach ($section['fields'] ?? [] as $index => $field) {
                $section['fields'][$index]['content'] = is_array($field['content']) && ! array_is_list($field['content'])
                    ? $field['content']
                    : DocumentHtml::fromHtml($field['content']);
            }
            $sections[] = $section;
        }

        /** @var list<array{key: string, title: string, orientation?: string, fields: list<array{key: string, label?: string|null, quien_llena: string, content: array<string, mixed>}>}> $sections */
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
    public static function presets(?TemplateDefinition $definition = null): array
    {
        return array_map(fn (array $preset): array => [
            'key' => $preset['key'],
            'field_key' => $preset['field'],
            'label' => $preset['label'],
            'quien_llena' => $preset['quien_llena'],
            'content' => $preset['content'],
        ], ($definition ?? TemplateContext::active())->presets());
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(): array
    {
        $raw = file_get_contents(base_path(self::PATH));
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['sections'] ?? null)) {
            throw new RuntimeException('La plantilla oficial ('.self::PATH.') no es un JSON válido.');
        }

        return $decoded;
    }
}
