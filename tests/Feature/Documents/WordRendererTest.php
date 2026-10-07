<?php

namespace Tests\Feature\Documents;

use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Documents\Application\SyllabusDocumentContent;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Syllabus\Application\IdentificationCard;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\Support\BuildsTypedSnapshot;
use Tests\TestCase;
use ZipArchive;

class WordRendererTest extends TestCase
{
    use BuildsTypedSnapshot;

    public function test_v7_snapshot_paints_each_field_with_its_type(): void
    {
        $input = new DocumentRenderInput(
            subject: 'Inteligencia Artificial', subjectCode: 'SW-P7-037', academicPeriod: 'Marzo – Julio 2026',
            revisionNumber: 1, revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-09-07T20:00:00-05:00',
            locale: 'es-EC', snapshot: $this->typedSnapshot(),
        );
        $docx = app(DocumentRenderer::class)->render($input)->docx;
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-typed-test-');
        file_put_contents($temporary, $docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $text = $dom->textContent;

        $this->assertStringContainsString('FACULTAD: Facultad de Pruebas Integradas', $text);
        $this->assertStringContainsString('Texto escrito por el docente', $text);
        $this->assertStringContainsString('Ficha heredada de la asignatura', $text);
        $this->assertStringContainsString('Descripción de la asignatura', $text);
        $this->assertStringNotContainsString('Identificación institucionalIdentificación institucional', $text);
        foreach (['Tema', 'Horas', 'Semana uno', 'Introducción', 'Agentes'] as $cell) {
            $this->assertStringContainsString($cell, $text);
        }
        $this->assertStringContainsString('Sin contenido', $text);
        $this->assertStringContainsString('2. Contenido', $text);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $this->assertGreaterThanOrEqual(2, $xpath->query('//w:tbl')->length);
        $weights = array_map(fn ($col) => (int) $col->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w'), iterator_to_array($xpath->query('//w:tbl[.//w:t[contains(., "FACULTAD:")]]/w:tblGrid/w:gridCol')));
        $this->assertCount(count(IdentificationCard::WIDTHS), $weights);
    }

    public function test_applies_the_frozen_template_appearance_to_docx(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['document_mapping'] = [
            'appearance' => [
                ...TemplateAppearance::defaults(),
                'font_family' => 'Georgia',
                'body_font_size' => 12,
                'title_font_size' => 18,
                'section_font_size' => 14,
                'field_font_size' => 12,
                'text_color' => '#1F4E78',
                'accent_color' => '#C00000',
                'table_header_background' => '#548235',
                'table_header_color' => '#FFFFFF',
                'margin_cm' => 2.0,
                'orientation' => 'landscape',
                'title_italic' => true,
                'section_alignment' => 'center',
                'body_alignment' => 'justify',
            ],
            'title_block' => ['text' => 'PLAN ACADÉMICO INSTITUCIONAL'],
        ];
        $input = new DocumentRenderInput(
            subject: 'Inteligencia Artificial',
            subjectCode: 'SW-P7-037',
            academicPeriod: 'Marzo – Julio 2026',
            revisionNumber: 3,
            revisionFingerprint: str_repeat('cd', 32),
            generatedAt: '2026-09-07T20:00:00-05:00',
            locale: 'es-EC',
            snapshot: $snapshot,
        );
        $this->assertSame(
            'PLAN ACADÉMICO INSTITUCIONAL',
            app(SyllabusDocumentContent::class)->lines($input)[0],
        );

        $document = app(DocumentRenderer::class)->render($input)->docx;
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-appearance-test-');
        $this->assertNotFalse($temporary);
        file_put_contents($temporary, $document->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $documentXml = $zip->getFromName('word/document.xml');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $zip->close();
        @unlink($temporary);

        $this->assertIsString($documentXml);
        $this->assertIsString($stylesXml);
        // La apariencia guardada se ignora: el documento usa el formato oficial fijo.
        $this->assertStringNotContainsString('w:orient="landscape"', $documentXml);
        $this->assertStringContainsString('w:top="1417"', $documentXml);
        $this->assertStringContainsString('w:fill="4F81BD"', $documentXml);
        $this->assertStringContainsString('w:color w:val="FFFFFF"', $documentXml);
        $this->assertStringContainsString('w:color w:val="2F5496"', $documentXml);
        $this->assertStringContainsString('w:sz w:val="20"', $documentXml);
        $this->assertStringContainsString('PLAN ACADÉMICO INSTITUCIONAL', $documentXml);
        $this->assertStringNotContainsString(TemplateTitleBlock::DEFAULT_TEXT, $documentXml);
        $this->assertStringNotContainsString('<w:i w:val="1"/>', $documentXml);
        $this->assertStringContainsString('Times New Roman', $stylesXml);
        $this->assertStringNotContainsString('Georgia', $stylesXml);
        $this->assertStringContainsString('w:color w:val="000000"', $stylesXml);
        $this->assertStringContainsString('w:sz w:val="20"', $stylesXml);
    }

    public function test_docx_follows_the_official_format(): void
    {
        $input = new DocumentRenderInput(
            subject: 'Inteligencia Artificial', subjectCode: 'SW-P7-037', academicPeriod: 'Marzo – Julio 2026',
            revisionNumber: 1, revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-09-07T20:00:00-05:00',
            locale: 'es-EC', snapshot: $this->typedSnapshot(),
        );
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-official-test-');
        file_put_contents($temporary, app(DocumentRenderer::class)->render($input)->docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $documentXml = (string) $zip->getFromName('word/document.xml');
        $stylesXml = (string) $zip->getFromName('word/styles.xml');
        $zip->close();
        unlink($temporary);

        $this->assertStringContainsString('Times New Roman', $stylesXml);
        $this->assertStringContainsString('w:jc w:val="both"', $stylesXml);
        $dom = new DOMDocument;
        $dom->loadXML($documentXml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $title = $xpath->query('//w:p[.//w:t[contains(., "'.TemplateTitleBlock::DEFAULT_TEXT.'")]]')->item(0);
        $this->assertNotNull($title);
        $this->assertSame(1, $xpath->query('.//w:rPr/w:b', $title)->length);
        $this->assertSame(1, $xpath->query('.//w:rPr/w:color[@w:val="2F5496"]', $title)->length);
        $this->assertSame(1, $xpath->query('./w:pPr/w:jc[@w:val="center"]', $title)->length);
        $boxes = $xpath->query('//w:tbl[w:tblPr/w:tblBorders/w:top[@w:color="0070C0"]][.//w:t[contains(., "Texto escrito por el docente")]]');
        $this->assertGreaterThanOrEqual(1, $boxes->length, 'El campo de texto debe ir en un recuadro de borde azul.');
        $identification = $xpath->query('//w:tbl[.//w:t[contains(., "FACULTAD:")]]')->item(0);
        $this->assertSame(0, $xpath->query('./w:tblPr/w:tblBorders/w:top[@w:color="0070C0"]', $identification)->length);
    }

    public function test_unnamed_part_prints_no_subtitle_and_keeps_numbering_consecutive(): void
    {
        $snapshot = $this->snapshot();
        array_splice($snapshot['sections'][2]['blocks'], 1, 0, [[
            'key' => 'parte_sin_nombre',
            'title' => '',
            'content_type' => 'text',
            'table' => null,
            'fields' => [['key' => 'parte_sin_nombre', 'label' => '', 'type' => 'texto_largo', 'value' => 'Contenido sin subtítulo']],
        ]]);
        $result = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Inteligencia Artificial',
            subjectCode: 'SW-P7-037',
            academicPeriod: 'Marzo – Julio 2026',
            revisionNumber: 3,
            revisionFingerprint: str_repeat('ab', 32),
            generatedAt: '2026-09-02T20:00:00-05:00',
            locale: 'es-EC',
            snapshot: $snapshot,
        ));

        $temporary = tempnam(sys_get_temp_dir(), 'silabos-test-');
        $this->assertNotFalse($temporary);
        file_put_contents($temporary, $result->docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($temporary);
        $this->assertIsString($document);

        $this->assertStringContainsString('Contenido sin subtítulo', $document);
        $this->assertStringContainsString('3.1 Descripción', $document);
        $this->assertStringContainsString('3.2 Objetivos', $document);
        $this->assertStringNotContainsString('3.3', $document);
        $this->assertStringNotContainsString('Campo', $document);
    }

    public function test_table_grids_match_cells_and_fill_the_usable_width(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['template_variables'] = TemplateVariables::resolve(['identification' => $snapshot['identification']]);
        $snapshot['sections'][0]['blocks'][0]['document'] = TemplateDocumentDefaults::identification();
        $snapshot['sections'][2]['blocks'][] = [
            'key' => 'firmas',
            'title' => 'Revisión',
            'content_type' => 'fixed',
            'document' => TemplateDocumentDefaults::signatures(),
            'fields' => [],
        ];
        $result = app(DocumentRenderer::class)->render(new DocumentRenderInput(
            subject: 'Inteligencia Artificial',
            subjectCode: 'SW-P7-037',
            academicPeriod: 'Marzo – Julio 2026',
            revisionNumber: 3,
            revisionFingerprint: str_repeat('ab', 32),
            generatedAt: '2026-09-02T20:00:00-05:00',
            locale: 'es-EC',
            snapshot: $snapshot,
        ));
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-grid-test-');
        $this->assertNotFalse($temporary);
        file_put_contents($temporary, $result->docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($temporary);
        $this->assertIsString($xml);

        $this->assertStringContainsString('MODALIDAD DE ESTUDIO', $xml);
        $this->assertStringNotContainsString('Dato automático', $xml);
        $this->assertStringNotContainsString('[[variable:', $xml);

        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $identification = false;
        foreach ($xpath->query('//w:tbl') ?: [] as $table) {
            $this->assertInstanceOf(DOMElement::class, $table);
            $grid = array_map(
                fn ($column): int => (int) ($column instanceof DOMElement ? $column->getAttribute('w:w') : 0),
                iterator_to_array($xpath->query('w:tblGrid/w:gridCol', $table) ?: []),
            );
            $total = array_sum($grid);
            $this->assertSame('fixed', $xpath->evaluate('string(w:tblPr/w:tblLayout/@w:type)', $table));
            $this->assertSame((string) $total, $xpath->evaluate('string(w:tblPr/w:tblW/@w:w)', $table));
            $this->assertContains($total, [9408, 9407, 9406, 9405, 9404, 13006, 13007, 13005, 13004], 'La tabla no ocupa el ancho útil.');
            foreach ($xpath->query('w:tr', $table) ?: [] as $row) {
                $columns = 0;
                $width = 0;
                foreach ($xpath->query('w:tc', $row) ?: [] as $cell) {
                    $span = (int) $xpath->evaluate('string(w:tcPr/w:gridSpan/@w:val)', $cell);
                    $cellWidth = (int) $xpath->evaluate('string(w:tcPr/w:tcW/@w:w)', $cell);
                    $this->assertSame(array_sum(array_slice($grid, $columns, max(1, $span))), $cellWidth);
                    $columns += max(1, $span);
                    $width += $cellWidth;
                }
                $this->assertSame(count($grid), $columns, 'Una fila no ocupa todas las columnas de la rejilla.');
                $this->assertSame($total, $width);
            }
            if (str_contains($table->textContent, 'MODALIDAD DE ESTUDIO')) {
                $identification = true;
                $this->assertCount(count(IdentificationCard::WIDTHS), $grid);
            }
        }
        $this->assertTrue($identification);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $paragraph = fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $cell = fn (string $text, string $type = 'tableCell'): array => ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [$paragraph($text)]];
        $planning = ['type' => 'doc', 'content' => [
            $paragraph('Unidad 1: Fundamentos teóricos'),
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [$cell('Contenidos', 'tableHeader'), $cell('Semana', 'tableHeader')]],
                ['type' => 'tableRow', 'content' => [$cell('Socialización'), $cell('1')]],
            ]],
        ]];
        $objectives = ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [$paragraph('Primer objetivo')]],
            ['type' => 'listItem', 'content' => [$paragraph('Segundo objetivo')]],
        ]]]];
        $sheet = fn (string $key, string $label): array => TemplateDocumentDefaults::freeTextSheet($key, $label);

        $snapshot = [
            'schema_version' => 2,
            'template_id' => '01a064a5-1d6d-7196-b189-05376ff0929d',
            'identification' => IdentificationCard::build(
                [
                    'career' => ['name' => 'Software', 'faculty' => 'Ciencias Administrativas, Gestión Empresarial e Informática'],
                    'subject' => ['name' => 'Inteligencia Artificial', 'code' => 'SW-P7-037', 'cycle' => 7, 'prerequisites' => ['SW-P6-032'], 'corequisites' => []],
                    'scheduled_subject' => ['period' => 'Marzo – Julio 2026', 'campus' => 'Matriz', 'modality' => 'Presencial'],
                ],
                ['A'],
                ['PAUL GUARANGA'],
                ['docente@ueb.edu.ec'],
            ),
            'sections' => [
                [
                    'key' => 'identificacion',
                    'title' => 'Identificación institucional',
                    'blocks' => [[
                        'key' => 'identificacion_principal',
                        'title' => 'Identificación institucional',
                        'content_type' => 'institutional',
                        'fields' => [['key' => 'asignatura', 'label' => 'Identificación institucional', 'type' => 'referencia_maestra', 'inherited' => true, 'master_source' => 'asignaturas', 'value' => ['codigo' => 'SW-P7-037']]],
                    ]],
                ],
                [
                    'key' => 'unidades',
                    'title' => 'Unidades y planificación',
                    'blocks' => [[
                        'key' => 'unidades_principal',
                        'title' => 'Planificación',
                        'content_type' => 'text',
                        'document' => $sheet('planificacion', 'Planificación'),
                        'fields' => [['key' => 'planificacion', 'label' => 'Planificación', 'type' => 'texto_libre', 'value' => $planning]],
                    ]],
                ],
                [
                    'key' => 'descripcion',
                    'title' => 'Descripción de la asignatura',
                    'blocks' => [
                        [
                            'key' => 'descripcion_principal',
                            'title' => 'Descripción',
                            'content_type' => 'text',
                            'fields' => [['key' => 'descripcion', 'label' => 'Descripción', 'type' => 'texto_largo', 'value' => "Descripción libre\n- Punto uno"]],
                        ],
                        [
                            'key' => 'objetivos',
                            'title' => 'Objetivos',
                            'content_type' => 'text',
                            'document' => $sheet('objetivos', 'Objetivos'),
                            'fields' => [['key' => 'objetivos', 'label' => 'Objetivos', 'type' => 'texto_libre', 'value' => $objectives]],
                        ],
                    ],
                ],
            ],
        ];
        $snapshot['template_variables'] = TemplateVariables::resolve(['identification' => $snapshot['identification']]);
        $snapshot['sections'][0]['blocks'][0]['document'] = TemplateDocumentDefaults::identification();

        return $snapshot;
    }
}
