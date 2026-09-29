<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Application\TemplateDocumentResolver;
use PhpOffice\PhpWord\Element\Section;

/** Contenido de cada campo: documento de plantilla o texto. */
final class WordFieldRenderer
{
    public function __construct(private readonly WordRenderContext $context) {}

    /**
     * Una parte sin nombre en la plantilla no imprime subtítulo.
     *
     * @param  array<string, mixed>  $container
     */
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
            (new TemplateDocumentWord(
                $this->context->hasAppearance ? (string) $this->context->appearance['body_alignment'] : null,
                $this->context->hasAppearance ? $this->context->color('table_header_background') : null,
                $this->context->hasAppearance ? $this->context->color('table_header_color') : null,
                (int) $this->context->appearance['body_font_size'],
            ))->append($section, $resolved, $this->context->contentWidth);

            return;
        }
        // Una parte oculta por «Mostrar solo si» no imprime valor.
        if (($field['hidden'] ?? false) === true) {
            return;
        }
        $this->text($section, $field['value'] ?? null);
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
