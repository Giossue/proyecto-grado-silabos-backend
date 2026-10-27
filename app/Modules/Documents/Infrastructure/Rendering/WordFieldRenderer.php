<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Syllabus\Application\IdentificationCard;
use PhpOffice\PhpWord\Element\Section;

final class WordFieldRenderer
{
    public function __construct(private readonly WordRenderContext $context) {}

    /**
     * @param  array<string, mixed>  $container
     */
    public static function hasTitle(array $container): bool
    {
        return WordValues::string($container['title'] ?? null, '') !== '';
    }

    /**
     * @param  array<string, mixed>  $container
     */
    public function render(Section $section, array $container, bool $titled): void
    {
        $fields = WordValues::arrayList($container['fields'] ?? null);
        $field = $fields[0] ?? [];
        $label = WordValues::string($field['label'] ?? null, WordValues::string($container['title'] ?? null, 'Campo'));
        if ($titled && self::hasTitle($container)) {
            $section->addText(
                $label,
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
     * @param  array<string, mixed>  $snapshotSection
     */
    public function renderDocuments(Section $section, array $snapshotSection): void
    {
        $fields = WordValues::arrayList($snapshotSection['fields'] ?? null);
        $historicalCard = array_filter($fields, fn (array $field): bool => ($field['type'] ?? null) === 'automatico' && ($field['config']['source'] ?? null) === 'identification_card') !== [];
        foreach ($fields as $field) {
            if ($historicalCard && ($field['type'] ?? null) === 'texto' && (($field['config']['inside'] ?? null) === 'identification_card' || array_key_exists((string) ($field['key'] ?? ''), IdentificationCard::INPUT_KEYS))) {
                continue;
            }
            $label = WordValues::string($field['label'] ?? null, '');
            if ($label !== '') {
                $section->addText($label,
                    ['bold' => true, 'size' => $this->context->appearance['field_font_size'], 'color' => $this->context->color('text_color')],
                    ['spaceBefore' => 160, 'spaceAfter' => 80, 'keepNext' => true]);
            }
            $document = TemplateDocumentResolver::resolve(HistoricalFieldDocument::of($field), [], $this->context->templateVariables);
            $document = FieldDocument::isEmpty($document) ? null : $document;
            if (in_array($field['type'] ?? null, ['texto', 'ficha'], true)) {
                $this->context->writeBoxed($section, $document);
            } else {
                $this->context->writeDocument($section, $document);
            }
        }
    }

    private function text(Section $section, mixed $value): void
    {
        $display = WordValues::display($value);
        if ($display === '') {
            if ($this->context->emptyText !== '') {
                $section->addText($this->context->emptyText, ['italic' => true, 'color' => $this->context->emptyColor]);
            }

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
