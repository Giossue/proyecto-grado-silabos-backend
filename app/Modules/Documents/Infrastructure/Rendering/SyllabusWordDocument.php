<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Domain\TableLayout;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * Arma el sílabo en Word con hoja carta, títulos numerados y tablas complejas. La
 * apariencia acotada se lee de la copia de la plantilla incluida en la revisión.
 *
 * Orquesta el documento (hojas, logos, títulos y pie) y delega cada campo en
 * `WordFieldRenderer`, que usa `WordIdentificationTable` y `WordDataTable`.
 */
class SyllabusWordDocument
{
    private WordRenderContext $context;

    public function build(DocumentRenderInput $input): PhpWord
    {
        Settings::setOutputEscapingEnabled(true);
        $mapping = is_array($input->snapshot['document_mapping'] ?? null)
            ? $input->snapshot['document_mapping']
            : null;
        $this->context = new WordRenderContext(
            appearance: TemplateAppearance::fromMapping($mapping),
            hasAppearance: is_array($mapping['appearance'] ?? null),
            snapshotIdentification: $input->snapshot['identification'] ?? null,
            templateVariables: $input->snapshot['template_variables'] ?? [],
            academicContext: is_array($input->snapshot['academic_context'] ?? null)
                ? $input->snapshot['academic_context']
                : [],
        );
        $fields = new WordFieldRenderer(
            $this->context,
            new WordIdentificationTable($this->context),
            new WordDataTable($this->context),
        );
        $title = TemplateTitleBlock::fromMapping($mapping)['text'];
        $margin = (int) round(Converter::cmToTwip((float) $this->context->appearance['margin_cm']));
        $pageWidth = $this->context->appearance['orientation'] === 'landscape' ? 15840 : 12240;
        $this->context->contentWidth = $pageWidth - (2 * $margin);
        $paragraph = [
            'spaceAfter' => 120,
            'spaceBefore' => 0,
            'lineHeight' => 1.15,
            'alignment' => $this->context->alignment($this->context->appearance['body_alignment']),
        ];

        $word = new PhpWord;
        $word->setDefaultFontName((string) $this->context->appearance['font_family']);
        $word->setDefaultFontSize((int) $this->context->appearance['body_font_size']);
        $word->setDefaultFontColor($this->context->color('text_color'));
        $word->setDefaultParagraphStyle($paragraph);

        // Fechas fijas: el mismo contenido produce los mismos bytes (huella estable).
        $timestamp = strtotime($input->generatedAt) ?: 0;
        $info = $word->getDocInfo();
        $info->setCreator('Sílabos UEB');
        $info->setTitle($input->subject);
        $info->setDescription('Revisión '.$input->revisionNumber);
        $info->setCreated($timestamp);
        $info->setModified($timestamp);

        $word->addNumberingStyle('silabo-bullets', [
            'type' => 'multilevel',
            'levels' => [['format' => 'bullet', 'text' => '•', 'left' => 360, 'hanging' => 360, 'font' => 'Symbol']],
        ]);
        $word->addNumberingStyle('silabo-numbers', [
            'type' => 'multilevel',
            'levels' => [['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360]],
        ]);

        $currentOrientation = (string) $this->context->appearance['orientation'];
        $section = $this->addSection($word, $currentOrientation, $margin);

        $this->logos($section, $input);
        $section->addText(
            $title,
            [
                'bold' => $this->context->appearance['title_bold'],
                'italic' => $this->context->appearance['title_italic'],
                'size' => $this->context->appearance['title_font_size'],
                'color' => $this->context->color('accent_color'),
            ],
            [
                'alignment' => $this->context->alignment($this->context->appearance['title_alignment']),
                'spaceBefore' => 240,
                'spaceAfter' => 240,
            ],
        );

        foreach (WordValues::arrayList($input->snapshot['sections'] ?? null) as $sectionIndex => $block) {
            $desiredOrientation = $this->sectionOrientation($block);
            if ($desiredOrientation !== $currentOrientation) {
                $section = $this->addSection($word, $desiredOrientation, $margin);
                $currentOrientation = $desiredOrientation;
            }
            $number = $sectionIndex + 1;
            $section->addText(
                $number.'. '.WordValues::string($block['title'] ?? null, 'Bloque'),
                [
                    'bold' => $this->context->appearance['section_bold'],
                    'italic' => $this->context->appearance['section_italic'],
                    'size' => $this->context->appearance['section_font_size'],
                    'color' => $this->context->color('text_color'),
                ],
                [
                    'alignment' => $this->context->alignment($this->context->appearance['section_alignment']),
                    'spaceBefore' => 240,
                    'spaceAfter' => 120,
                    'keepNext' => true,
                ],
            );
            $containers = WordValues::arrayList($block['blocks'] ?? null);
            $partNumber = 0;
            foreach ($containers as $container) {
                // Un solo campo en la sección: basta el título de la sección. Una parte sin
                // nombre no lleva subtítulo ni consume número (6.1, 6.2 siguen seguidos).
                $numbered = count($containers) > 1 && WordFieldRenderer::hasTitle($container);
                $fields->render($section, $container, $numbered ? $number.'.'.(++$partNumber) : null);
            }
        }

        $this->metadata($section, $input);

        return $word;
    }

    public function __construct(private readonly InstitutionalLogos $logos) {}

    private function addSection(PhpWord $word, string $orientation, int $margin): Section
    {
        $pageWidth = $orientation === 'landscape' ? 15840 : 12240;
        $this->context->contentWidth = $pageWidth - (2 * $margin);

        return $word->addSection([
            'paperSize' => 'Letter',
            'orientation' => $orientation,
            'marginTop' => $margin,
            'marginBottom' => $margin,
            'marginLeft' => $margin,
            'marginRight' => $margin,
        ]);
    }

    /** @param array<string, mixed> $snapshotSection */
    private function sectionOrientation(array $snapshotSection): string
    {
        foreach (WordValues::arrayList($snapshotSection['blocks'] ?? null) as $block) {
            $orientation = $block['page_orientation'] ?? null;
            if (in_array($orientation, ['portrait', 'landscape'], true)) {
                return $orientation;
            }
            if (is_array($block['table'] ?? null)) {
                $layout = TableLayout::normalize($block['table']);
                if (TableLayout::isPlanning($layout)) {
                    return 'landscape';
                }
            }
        }

        return (string) $this->context->appearance['orientation'];
    }

    /** Encabezado: logo de la universidad y logo de la facultad de la carrera. */
    private function logos(Section $section, DocumentRenderInput $input): void
    {
        $facultyId = data_get($input->snapshot, 'academic_context.career.faculty_id');
        $ueb = $this->logos->institutionPath();
        $faculty = $this->logos->facultyPathById(is_string($facultyId) ? $facultyId : null);
        if (! is_file($ueb) && ! is_file($faculty)) {
            return;
        }

        $table = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $row = $table->addRow();
        $leftWidth = (int) round($this->context->contentWidth * 0.638);
        $left = $row->addCell($leftWidth, ['valign' => 'center']);
        if (is_file($ueb)) {
            $left->addImage($ueb, ['height' => 28]);
        }
        $right = $row->addCell($this->context->contentWidth - $leftWidth, ['valign' => 'center']);
        if (is_file($faculty)) {
            $right->addImage($faculty, ['height' => 40, 'alignment' => Jc::END]);
        }
    }

    /** Pie con la trazabilidad de la revisión: huella y fecha, en letra pequeña. */
    private function metadata(Section $section, DocumentRenderInput $input): void
    {
        $section->addText(
            sprintf(
                'Revisión %d · %s · huella %s · generado %s',
                $input->revisionNumber,
                $input->subject.' ('.$input->subjectCode.')',
                $input->revisionFingerprint,
                $input->generatedAt,
            ),
            ['size' => 7, 'color' => '7F7F7F'],
            ['spaceBefore' => 360, 'spaceAfter' => 0],
        );
    }
}
