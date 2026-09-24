<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Domain\TableLayout;
use App\Modules\Syllabus\Application\AcademicContextValues;
use App\Modules\Syllabus\Application\IdentificationCard;
use PhpOffice\PhpWord\Element\Section;

/** Contenido de cada campo: documento de plantilla, ficha, tabla, lista o texto. */
final class WordFieldRenderer
{
    public function __construct(
        private readonly WordRenderContext $context,
        private readonly WordIdentificationTable $identification,
        private readonly WordDataTable $tables,
    ) {}

    /** @param array<string, mixed> $container */
    public function render(Section $section, array $container, ?string $number): void
    {
        $fields = WordValues::arrayList($container['fields'] ?? null);
        $field = $fields[0] ?? [];
        $label = WordValues::string($field['label'] ?? null, WordValues::string($container['title'] ?? null, 'Campo'));
        if ($number !== null) {
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

        $contentType = WordValues::string($container['content_type'] ?? null, 'text');
        if (is_array($container['document'] ?? null)) {
            $resolved = TemplateDocumentResolver::resolve(
                $container['document'],
                $fields,
                $this->context->templateVariables,
                $container['table'] ?? null,
                $this->planningExpectations(),
            );
            (new TemplateDocumentWord(
                $this->context->hasAppearance ? (string) $this->context->appearance['body_alignment'] : null,
                $this->context->hasAppearance ? $this->context->color('table_header_background') : null,
                $this->context->hasAppearance ? $this->context->color('table_header_color') : null,
            ))->append($section, $resolved, $this->context->contentWidth);

            return;
        }
        $rows = WordValues::arrayList($field['rows'] ?? null);

        // Bloque institucional: la ficha ya viene armada en la copia de la revisión.
        if ($contentType === 'institutional' || ($field['master_source'] ?? null) === 'asignaturas') {
            if (is_array($this->context->snapshotIdentification)) {
                $this->identification->render($section, IdentificationCard::grid($this->context->snapshotIdentification));

                return;
            }
        }

        if ($contentType === 'table' || ($rows !== [] && $contentType === 'text')) {
            $layout = is_array($container['table'] ?? null)
                ? TableLayout::normalize($container['table'])
                : TableLayout::default();
            $this->tables->render($section, $layout, $rows);

            return;
        }

        if ($contentType === 'bulleted_list' || $contentType === 'numbered_list') {
            $style = $contentType === 'numbered_list' ? 'silabo-numbers' : 'silabo-bullets';
            if ($rows === []) {
                $section->addText('Sin contenido', ['italic' => true, 'color' => '595959']);
            }
            foreach ($rows as $row) {
                $section->addListItem(WordValues::cell(WordValues::rowData($row), 'texto'), 0, [], $style, ['spaceAfter' => 60]);
            }

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

    /** @return array<string, mixed> */
    private function planningExpectations(): array
    {
        return [
            'teaching_weeks' => AcademicContextValues::scheduledSubject($this->context->academicContext, 'teaching_weeks'),
            'credits' => data_get($this->context->academicContext, 'subject.credits'),
            'hours_acd' => data_get($this->context->academicContext, 'subject.hours_ac'),
            'hours_ape' => data_get($this->context->academicContext, 'subject.hours_pae'),
            'hours_aa' => data_get($this->context->academicContext, 'subject.hours_aa'),
        ];
    }
}
