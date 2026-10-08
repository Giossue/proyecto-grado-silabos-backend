<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
use App\Modules\Syllabus\Application\IdentificationCard;
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
    public function renderTyped(Section $section, array $snapshotSection): void
    {
        $types = app(FieldTypeRegistry::class);
        $fields = WordValues::arrayList($snapshotSection['fields'] ?? null);
        // Lo que el docente escribe y se imprime dentro de la tabla de identificación no se repite debajo.
        $card = array_filter($fields, fn (array $field): bool => ($field['type'] ?? null) === 'automatico' && ($field['config']['source'] ?? null) === 'identification_card') !== [];
        foreach ($fields as $field) {
            if ($card && ($field['type'] ?? null) === 'texto' && (($field['config']['inside'] ?? null) === 'identification_card' || array_key_exists((string) ($field['key'] ?? ''), IdentificationCard::INPUT_KEYS))) {
                continue;
            }
            $type = $types->get((string) $field['type']);
            $label = WordValues::string($field['label'] ?? null, '');
            if ($type->key() !== 'automatico' && $label !== '') {
                $section->addText(
                    $label,
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
