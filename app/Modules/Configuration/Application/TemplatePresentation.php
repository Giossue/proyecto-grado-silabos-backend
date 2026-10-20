<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Domain\TemplateStyle;

final class TemplatePresentation
{
    /**
     * @return array{document: array<string, mixed>, blocks: array<string, array<string, mixed>>}
     */
    public static function definition(TemplateDefinition $definition): array
    {
        return ['document' => $definition->document(), 'blocks' => $definition->blocks()];
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
}
