<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Domain\TemplateStyle;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;

final class TemplatePresentation
{
    /**
     * @return array{document: array<string, mixed>, blocks: array<string, array<string, mixed>>, response_policy?: string}
     */
    public static function definition(TemplateDefinition $definition): array
    {
        return ['document' => $definition->document(), 'blocks' => $definition->blocks(), ...(($definition->toArray()['response_policy'] ?? null) === 'protected' ? ['response_policy' => 'protected'] : [])];
    }

    /**
     * @param  array<string, mixed>  $logos
     * @return array<string, mixed>
     */
    public static function presentation(TemplateDefinition $definition, array $logos): array
    {
        return [
            'document' => $definition->document(),
            'appearance' => TemplateStyle::appearance($definition->document()),
            'title' => $definition->document()['title']['text'],
            'logos' => $logos,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, array<int, array{value: int|float, label: string}>>
     */
    public static function layoutOptions(array $document): array
    {
        $points = fn (array $values): array => array_map(fn (int $value): array => ['value' => $value, 'label' => "{$value} pt"], $values);

        return [
            'body_sizes' => $points($document['choices']['body']),
            'title_sizes' => $points($document['choices']['title']),
            'section_sizes' => $points($document['choices']['section']),
            'margins' => array_map(fn (float $margin): array => ['value' => $margin, 'label' => number_format($margin, 1, ',', '').' cm'], $document['choices']['margin_cm']),
        ];
    }

    /**
     * @return list<array{id: string, key: string, title: string, orientation: string, fields: list<array{id: string, key: string, label: string|null, quien_llena: string, content: mixed}>}>
     */
    public static function sections(SyllabusTemplate $template): array
    {
        return array_values($template->sections->map(fn (TemplateSection $section): array => [
            'id' => (string) $section->id,
            'key' => (string) $section->clave,
            'title' => (string) $section->titulo,
            'orientation' => (string) $section->orientacion,
            'fields' => array_values($section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(fn (FieldDefinition $field): array => [
                'id' => (string) $field->id,
                'key' => (string) $field->clave,
                'label' => $field->etiqueta,
                'quien_llena' => (string) $field->quien_llena,
                'content' => $field->contenido,
            ])->all())->values()->all()),
        ])->values()->all());
    }
}
