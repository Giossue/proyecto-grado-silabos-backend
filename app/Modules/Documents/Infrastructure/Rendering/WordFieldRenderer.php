<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
use PhpOffice\PhpWord\Element\Section;

final class WordFieldRenderer
{
    public function __construct(private readonly WordRenderContext $context) {}

    /** @param  array<string, mixed>  $container */
    public static function hasTitle(array $container): bool
    {
        return WordValues::string($container['title'] ?? null, '') !== '';
    }

    /** @param array<string, mixed> $container */
    public function render(Section $section, array $container, ?string $number): void
    {
        $fields = WordValues::arrayList($container['fields'] ?? null);
        $field = $fields[0] ?? [];
        $label = WordValues::string($field['label'] ?? null, WordValues::string($container['title'] ?? null, 'Campo'));
        if ($number !== null && self::hasTitle($container)) {
            $section->addText(
                $number.' '.$label,
                [
                    'bold' => true,
                    'size' => $this->context->appearance['field_font_size'],
                    'color' => $this->context->color('text_color'),
                ],
                ['spaceBefore' => 160, 'spaceAfter' => 80, 'keepNext' => true],
            );
        }

        if (is_array($container['document'] ?? null)) {
            $resolved = TemplateDocumentResolver::resolve($container['document'], $fields, $this->context->templateVariables);
            $this->context->documentWriter()->append($section, $resolved, $this->context->contentWidth);

            return;
        }
        if (($field['hidden'] ?? false) === true) {
            return;
        }
        $this->text($section, $field['value'] ?? null);
    }

    /**
     * Recorrido del snapshot v7: los campos planos de la sección, cada uno pintado por su tipo.
     * Una sección con algún campo de la clase anterior usa `render` (deuda de la etapa 8).
     *
     * @param  array<string, mixed>  $snapshotSection
     */
    public static function usesTypes(array $snapshotSection): bool
    {
        $fields = WordValues::arrayList($snapshotSection['fields'] ?? null);

        return $fields !== [] && array_filter($fields, fn (array $field): bool => ($field['legacy'] ?? false) === true || ! is_string($field['type'] ?? null)) === [];
    }

    /** @param array<string, mixed> $snapshotSection */
    public function renderTyped(Section $section, array $snapshotSection, int $number): void
    {
        $types = app(FieldTypeRegistry::class);
        $fields = WordValues::arrayList($snapshotSection['fields'] ?? null);
        $part = 0;
        foreach ($fields as $field) {
            $type = $types->get((string) $field['type']);
            $label = WordValues::string($field['label'] ?? null, '');
            if ($type->key() !== 'automatico' && $label !== '') {
                $prefix = count($fields) > 1 ? $number.'.'.(++$part).' ' : '';
                $section->addText(
                    $prefix.$label,
                    ['bold' => true, 'size' => $this->context->appearance['field_font_size'], 'color' => $this->context->color('text_color')],
                    ['spaceBefore' => 160, 'spaceAfter' => 80, 'keepNext' => true],
                );
            }
            $type->toWord($section, $field, $this->context);
        }
    }

    private function text(Section $section, mixed $value): void
    {
        $display = WordValues::display($value);
        if ($display === '') {
            $section->addText('Sin contenido', ['italic' => true, 'color' => '595959']);

            return;
        }

        foreach (preg_split('/\R/u', $display) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^[-*•]\s+(.*)$/u', $trimmed, $match) === 1) {
                $section->addListItem($match[1], 0, [], 'silabo-bullets', ['spaceAfter' => 60]);

                continue;
            }
            $section->addText($trimmed);
        }
    }
}
