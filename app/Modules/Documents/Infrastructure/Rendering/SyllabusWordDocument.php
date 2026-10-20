<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\TemplateStyle;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

class SyllabusWordDocument
{
    private WordRenderContext $context;

    /** @var array<string, mixed> */
    private array $document = [];

    public function build(DocumentRenderInput $input): PhpWord
    {
        $definition = TemplateContext::fromSnapshot($input->snapshot);

        return app(TemplateContext::class)->using($definition, fn (): PhpWord => $this->render($input, $definition->document()));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function render(DocumentRenderInput $input, array $document): PhpWord
    {
        Settings::setOutputEscapingEnabled(true);
        $this->document = $document;
        $this->context = new WordRenderContext(
            appearance: TemplateStyle::appearance($document),
            hasAppearance: $document['colors']['table_header_background'] !== null,
            templateVariables: $input->snapshot['template_variables'] ?? [],
            academicContext: is_array($input->snapshot['academic_context'] ?? null)
                ? $input->snapshot['academic_context']
                : [],
            emptyText: (string) $document['empty_text'],
            emptyColor: ltrim((string) $document['colors']['empty_text'], '#'),
        );
        $fields = new WordFieldRenderer($this->context);
        $title = (string) $document['title']['text'];
        $margin = (int) round(Converter::cmToTwip((float) $this->context->appearance['margin_cm']));
        $pageWidth = $this->pageWidth((string) $this->context->appearance['orientation']);
        $this->context->contentWidth = $pageWidth - (2 * $margin);
        $paragraph = [
            'spaceAfter' => (int) round($document['paragraph_spacing_pt'] * 20),
            'spaceBefore' => 0,
            'lineHeight' => (float) $document['line_height'],
            'alignment' => $this->context->alignment($this->context->appearance['body_alignment']),
        ];

        $word = new PhpWord;
        $word->setDefaultFontName((string) $this->context->appearance['font_family']);
        $word->setDefaultFontSize((int) $this->context->appearance['body_font_size']);
        $word->setDefaultFontColor($this->context->color('text_color'));
        $word->setDefaultParagraphStyle($paragraph);

        $timestamp = strtotime($input->generatedAt) ?: 0;
        $info = $word->getDocInfo();
        $info->setCreator('Sílabos UEB');
        $info->setTitle($input->subject);
        $info->setDescription('Revisión '.$input->revisionNumber);
        $info->setCreated($timestamp);
        $info->setModified($timestamp);

        $word->addNumberingStyle('silabo-bullets', [
            'type' => 'multilevel',
            'levels' => [
                ['format' => 'bullet', 'text' => '•', 'left' => 360, 'hanging' => 360, 'font' => 'Symbol'],
                ['format' => 'bullet', 'text' => 'o', 'left' => 720, 'hanging' => 360, 'font' => 'Courier New'],
                ['format' => 'bullet', 'text' => '•', 'left' => 1080, 'hanging' => 360, 'font' => 'Symbol'],
            ],
        ]);
        $word->addNumberingStyle('silabo-numbers', [
            'type' => 'multilevel',
            'levels' => [
                ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360],
                ['format' => 'lowerLetter', 'text' => '%2.', 'left' => 720, 'hanging' => 360],
                ['format' => 'lowerRoman', 'text' => '%3.', 'left' => 1080, 'hanging' => 360],
            ],
        ]);

        $currentOrientation = (string) $this->context->appearance['orientation'];
        $section = $this->addSection($word, $currentOrientation, $margin);
        $currentCompact = false;
        $activeFields = $fields;

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
            $desiredCompact = $this->sectionCompact($block);
            if ($desiredOrientation !== $currentOrientation || $desiredCompact !== $currentCompact) {
                $sectionMargin = $desiredCompact ? (int) round(Converter::cmToTwip(WordRenderContext::COMPACT_MARGIN_CM)) : $margin;
                $section = $this->addSection($word, $desiredOrientation, $sectionMargin);
                $currentOrientation = $desiredOrientation;
                $currentCompact = $desiredCompact;
                $pageWidth = $this->pageWidth($desiredOrientation);
                $activeFields = $desiredCompact ? new WordFieldRenderer($this->context->compact($pageWidth)) : $fields;
            }
            $section->addText(
                WordValues::string($block['title'] ?? null, 'Bloque'),
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
            if (is_array($block['fields'] ?? null) && $block['fields'] !== [] && array_filter($block['fields'], fn (array $field): bool => ($field['legacy'] ?? false) === true) === []) {
                $activeFields->renderDocuments($section, $block);

                continue;
            }
            $containers = WordValues::arrayList($block['blocks'] ?? null);
            foreach ($containers as $container) {
                $activeFields->render($section, $container, count($containers) > 1 && WordFieldRenderer::hasTitle($container));
            }
        }

        $this->metadata($section, $input);

        return $word;
    }

    public function __construct(private readonly InstitutionalLogos $logos) {}

    private function addSection(PhpWord $word, string $orientation, int $margin): Section
    {
        $this->context->contentWidth = $this->pageWidth($orientation) - (2 * $margin);

        $section = $word->addSection([
            'paperSize' => match ($this->document['paper']) {
                'a4' => 'A4',
                'legal' => 'Legal',
                default => 'Letter',
            },
            'orientation' => $orientation,
            'marginTop' => $margin,
            'marginBottom' => $margin,
            'marginLeft' => $margin,
            'marginRight' => $margin,
        ]);
        $footer = $this->document['footer'];
        if ($footer !== null) {
            $section->addFooter()->addPreserveText(
                strtr($footer['text'], ['{page}' => '{PAGE}', '{pages}' => '{NUMPAGES}']),
                ['size' => $footer['size'], 'color' => ltrim($footer['color'], '#')],
                ['alignment' => Jc::CENTER, 'spaceBefore' => 0, 'spaceAfter' => 0],
            );
        }

        return $section;
    }

    private function pageWidth(string $orientation): int
    {
        [$width, $height] = TemplateStyle::PAPERS[$this->document['paper']];

        return $orientation === 'landscape' ? $height : $width;
    }

    /**
     * @param  array<string, mixed>  $snapshotSection
     */
    private function sectionOrientation(array $snapshotSection): string
    {
        if (in_array($snapshotSection['orientation'] ?? null, ['portrait', 'landscape'], true)) {
            return $snapshotSection['orientation'];
        }
        foreach (WordValues::arrayList($snapshotSection['blocks'] ?? null) as $block) {
            $orientation = $block['page_orientation'] ?? null;
            if (in_array($orientation, ['portrait', 'landscape'], true)) {
                return $orientation;
            }
        }

        return (string) $this->context->appearance['orientation'];
    }

    /**
     * @param  array<string, mixed>  $snapshotSection
     */
    private function sectionCompact(array $snapshotSection): bool
    {
        foreach (WordValues::arrayList($snapshotSection['blocks'] ?? null) as $block) {
            if (($block['compact'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function logos(Section $section, DocumentRenderInput $input): void
    {
        $facultyId = data_get($input->snapshot, 'academic_context.career.faculty_id');
        $embedded = is_array($input->snapshot['logos'] ?? null) ? $input->snapshot['logos'] : null;
        $sources = [
            'institution' => fn (): ?string => $this->logoSource($embedded['institution'] ?? null, $this->logos->institutionPath()),
            'faculty' => fn (): ?string => $this->logoSource($embedded['faculty'] ?? null, $this->logos->facultyPathById(is_string($facultyId) ? $facultyId : null)),
        ];
        $header = $this->document['header'];
        $images = array_map(fn (array $logo): array => [...$logo, 'image' => $sources[$logo['source']]()], $header['logos']);
        if (array_filter(array_column($images, 'image')) === []) {
            return;
        }

        $table = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $row = $table->addRow();
        $leftWidth = (int) round($this->context->contentWidth * $header['left_share']);
        $left = $row->addCell($leftWidth, ['valign' => 'center']);
        if (($images[0]['image'] ?? null) !== null) {
            $left->addImage($images[0]['image'], ['height' => $images[0]['height_pt']]);
        }
        $right = $row->addCell($this->context->contentWidth - $leftWidth, ['valign' => 'center']);
        if (($images[1]['image'] ?? null) !== null) {
            $right->addImage($images[1]['image'], ['height' => $images[1]['height_pt'], 'alignment' => Jc::END]);
        }
    }

    private function logoSource(mixed $embedded, string $currentPath): ?string
    {
        if (is_string($embedded) && $embedded !== '') {
            $binary = base64_decode($embedded, true);
            if ($binary !== false) {
                return $binary;
            }
        }

        return is_file($currentPath) ? $currentPath : null;
    }

    private function metadata(Section $section, DocumentRenderInput $input): void
    {
        $metadata = $this->document['metadata'];
        if ($metadata === null) {
            return;
        }
        $section->addText(
            strtr($metadata['text'], [
                '{revision}' => (string) $input->revisionNumber,
                '{subject}' => $input->subject,
                '{code}' => $input->subjectCode,
                '{fingerprint}' => $input->revisionFingerprint,
                '{generated}' => $input->generatedAt,
                '{period}' => $input->academicPeriod,
            ]),
            ['size' => $metadata['size'], 'color' => ltrim($metadata['color'], '#')],
            ['spaceBefore' => 360, 'spaceAfter' => 0],
        );
    }
}
