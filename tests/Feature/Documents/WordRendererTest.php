<?php

namespace Tests\Feature\Documents;

use App\Modules\Configuration\Application\FixedContent;
use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
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
    private const OFFICIAL_TITLE = 'PROGRAMA DE ASIGNATURA (SÍLABO)';

    use BuildsTypedSnapshot;

    public function test_v8_official_documents_render_generic_tables_variables_and_landscape(): void
    {
        $input = $this->officialInput();
        $variables = $input->snapshot['template_variables'];
        $bundle = app(DocumentRenderer::class)->render($input);
        [$xml, $dom, $xpath] = $this->documentXml($bundle->docx->bytes);
        $this->assertSame(12, $xpath->query('//w:tbl[.//w:t]')->length);
        $this->assertSame(4, $xpath->query('//w:tbl[.//w:t[contains(., "Contenidos temáticos")]]')->length);
        $this->assertSame(1, $xpath->query('//w:tbl[.//w:t[contains(., "Contenidos temáticos")]]/w:tr[.//w:t[text()="16"] and not(.//w:t[contains(., "Contenidos temáticos")])]')->length);
        $this->assertStringContainsString('w:orient="landscape"', $xml);
        foreach (['FACULTAD:', 'Semanas (16)', 'Puntualidad:', 'Firma del docente', 'Firma de coordinación', 'Firma de biblioteca', 'Bibliografía verificada por', 'Fecha de revisión:', 'Nivel de contribución', 'ISBN'] as $text) {
            $this->assertStringContainsString($text, $dom->textContent);
        }
        $this->assertStringNotContainsString('nombre_facultad', $dom->textContent);
        $this->assertStringNotContainsString('quien_llena', $dom->textContent);
        $this->assertStringStartsWith('%PDF-', $bundle->pdf->bytes);
        $lines = implode(' ', app(SyllabusDocumentContent::class)->lines($input));
        $this->assertStringContainsString($variables['nombre_docente'], $lines);
        $this->assertStringContainsString('Aprendizaje autónomo', $lines);
    }

    public function test_unit_headings_stay_together_with_their_first_week(): void
    {
        [, , $xpath] = $this->documentXml(app(DocumentRenderer::class)->render($this->officialInput())->docx->bytes);
        $unit = $xpath->query('//w:tbl[.//w:t[contains(., "Unidad No.")]]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $unit);
        $rows = iterator_to_array($xpath->query('w:tr', $unit) ?: []);

        foreach (array_slice($rows, 0, 5) as $row) {
            $this->assertSame(1, $xpath->query('w:trPr/w:cantSplit', $row)->length);
            $paragraphs = $xpath->query('.//w:p', $row)->length;
            $this->assertGreaterThan(0, $paragraphs);
            $this->assertSame($paragraphs, $xpath->query('.//w:p[w:pPr/w:keepNext]', $row)->length);
        }
        $this->assertStringContainsString('Unidad No.', $rows[0]->textContent);
        $this->assertSame(0, $xpath->query('.//w:keepNext', $rows[5])->length);
        $this->assertSame(0, $xpath->query('w:trPr/w:cantSplit', $rows[5])->length);
        $this->assertSame(0, $xpath->query('w:tr/w:trPr/w:tblHeader', $unit)->length);
    }

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
        $this->assertStringContainsString('Contenido', $text);
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
                ...['margin_cm' => 1.5, 'text_color' => '#000000', 'title_bold' => true, 'font_family' => 'Arial', 'orientation' => 'portrait', 'accent_color' => '#2F5496', 'section_bold' => true, 'title_italic' => false, 'body_alignment' => 'left', 'body_font_size' => 11, 'section_italic' => false, 'field_font_size' => 11, 'title_alignment' => 'center', 'title_font_size' => 14, 'section_alignment' => 'left', 'section_font_size' => 11, 'table_header_color' => '#FFFFFF', 'table_header_background' => '#4F81BD'],
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

        $this->assertStringNotContainsString('w:orient="landscape"', $documentXml);
        $this->assertMatchesRegularExpression('/w:top="85[01]"/', $documentXml);
        $this->assertStringContainsString('w:fill="4F81BD"', $documentXml);
        $this->assertStringContainsString('w:color w:val="FFFFFF"', $documentXml);
        $this->assertStringContainsString('w:color w:val="2F5496"', $documentXml);
        $this->assertStringContainsString('w:sz w:val="22"', $documentXml);
        $this->assertStringContainsString('PLAN ACADÉMICO INSTITUCIONAL', $documentXml);
        $this->assertStringNotContainsString(self::OFFICIAL_TITLE, $documentXml);
        $this->assertStringNotContainsString('<w:i w:val="1"/>', $documentXml);
        $this->assertStringContainsString('Arial', $stylesXml);
        $this->assertStringNotContainsString('Georgia', $stylesXml);
        $this->assertStringContainsString('w:color w:val="000000"', $stylesXml);
        $this->assertStringContainsString('w:sz w:val="22"', $stylesXml);
    }

    public function test_the_frozen_template_definition_sets_paper_title_and_footer(): void
    {
        $definition = OfficialTemplate::definition()->toArray();
        $definition['document'] = [...$definition['document'], 'paper' => 'a4', 'footer' => null, 'metadata' => null, 'title' => [...$definition['document']['title'], 'text' => 'SÍLABO DE PRUEBA']];
        $input = new DocumentRenderInput(
            subject: 'IA', subjectCode: 'SW-P7-037', academicPeriod: '2026', revisionNumber: 1,
            revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-09-07T20:00:00-05:00', locale: 'es-EC',
            snapshot: [...$this->typedSnapshot(), 'template_definition' => $definition],
        );
        [$xml] = $this->documentXml(app(DocumentRenderer::class)->render($input)->docx->bytes);

        $this->assertMatchesRegularExpression('/<w:pgSz [^>]*w:w="1190[56]/', $xml, 'La hoja es A4.');
        $this->assertStringContainsString('SÍLABO DE PRUEBA', $xml);
        $this->assertStringNotContainsString('Revisión 1 ·', $xml);
        $this->assertStringNotContainsString('footerReference', $xml);
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

        $this->assertStringContainsString('Arial', $stylesXml);
        $this->assertStringNotContainsString('w:jc w:val="both"', $stylesXml);
        $dom = new DOMDocument;
        $dom->loadXML($documentXml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $title = $xpath->query('//w:p[.//w:t[contains(., "'.self::OFFICIAL_TITLE.'")]]')->item(0);
        $this->assertNotNull($title);
        $this->assertSame(1, $xpath->query('.//w:rPr/w:b', $title)->length);
        $this->assertSame(1, $xpath->query('.//w:rPr/w:color[@w:val="2F5496"]', $title)->length);
        $this->assertSame(1, $xpath->query('./w:pPr/w:jc[@w:val="center"]', $title)->length);
        $this->assertSame(1, $xpath->query('.//w:rPr/w:sz[@w:val="28"]', $title)->length, 'El título va a 14 pt.');
        $this->assertStringNotContainsString('Times New Roman', $stylesXml.$documentXml);
        $boxes = $xpath->query('//w:tbl[w:tblPr/w:tblBorders/w:top[@w:color="0070C0"]][.//w:t[contains(., "Texto escrito por el docente")]]');
        $this->assertGreaterThanOrEqual(1, $boxes->length, 'El campo de texto debe ir en un recuadro de borde azul.');
        $identification = $xpath->query('//w:tbl[.//w:t[contains(., "FACULTAD:")]]')->item(0);
        $this->assertSame(0, $xpath->query('./w:tblPr/w:tblBorders/w:top[@w:color="0070C0"]', $identification)->length);
    }

    public function test_docx_honors_paragraph_alignment_and_layout_without_page_numbers(): void
    {
        $snapshot = $this->typedSnapshot();
        $snapshot['document_mapping'] = [
            'appearance' => ['margin_cm' => 1.5, 'text_color' => '#000000', 'title_bold' => true, 'font_family' => 'Arial', 'orientation' => 'portrait', 'accent_color' => '#2F5496', 'section_bold' => true, 'title_italic' => false, 'body_alignment' => 'left', 'body_font_size' => 11, 'section_italic' => false, 'field_font_size' => 11, 'title_alignment' => 'center', 'title_font_size' => 14, 'section_alignment' => 'left', 'section_font_size' => 11, 'table_header_color' => '#FFFFFF', 'table_header_background' => '#4F81BD'],
            'layout' => ['margin_cm' => 2.0, 'title_font_size' => 18, 'section_font_size' => 14, 'body_font_size' => 12],
        ];
        $snapshot['sections'][1]['fields'][0]['value'] = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Párrafo centrado']]],
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'justify'], 'content' => [['type' => 'text', 'text' => 'Párrafo justificado']]],
        ]];
        $input = new DocumentRenderInput(
            subject: 'IA', subjectCode: 'SW-P7-037', academicPeriod: '2026', revisionNumber: 1,
            revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-09-07T20:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        );
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-layout-test-');
        file_put_contents($temporary, app(DocumentRenderer::class)->render($input)->docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $documentXml = (string) $zip->getFromName('word/document.xml');
        $stylesXml = (string) $zip->getFromName('word/styles.xml');
        $footers = '';
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_starts_with($name, 'word/footer')) {
                $footers .= (string) $zip->getFromName($name);
            }
        }
        $zip->close();
        unlink($temporary);

        $dom = new DOMDocument;
        $dom->loadXML($documentXml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $this->assertSame(1, $xpath->query('//w:p[.//w:t[contains(., "Párrafo centrado")]]/w:pPr/w:jc[@w:val="center"]')->length);
        $this->assertSame(1, $xpath->query('//w:p[.//w:t[contains(., "Párrafo justificado")]]/w:pPr/w:jc[@w:val="both"]')->length);
        $this->assertMatchesRegularExpression('/w:top="113[34]"/', $documentXml, 'El margen de 2 cm del diseño se aplica.');
        $this->assertStringContainsString('w:sz w:val="24"', $stylesXml, 'El contenido va a 12 pt.');
        $title = $xpath->query('//w:p[.//w:t[contains(., "'.self::OFFICIAL_TITLE.'")]]')->item(0);
        $this->assertNotNull($title);
        $this->assertSame(1, $xpath->query('.//w:rPr/w:sz[@w:val="36"]', $title)->length, 'El título va a 18 pt.');
        $this->assertStringNotContainsString('Página', $footers);
        $this->assertStringNotContainsString('NUMPAGES', $footers);
    }

    public function test_identification_inputs_print_inside_the_table_and_not_again_below_it(): void
    {
        $snapshot = $this->typedSnapshot();
        $snapshot['sections'][0]['fields'][0]['value'] = IdentificationCard::build(
            ['career' => ['name' => 'Software', 'faculty' => 'Facultad de Pruebas Integradas'], 'subject' => ['name' => 'IA']],
            ['A'], ['DOCENTE'], ['d@ueb.edu.ec'],
            ['discapacidad_tipo' => 'Visual', 'formacion_experiencia' => 'Ingeniera de pruebas'],
        );
        foreach (['discapacidad_tipo' => 'Estudiantes con discapacidad: Tipo de discapacidad', 'formacion_experiencia' => 'Formación y experiencia'] as $key => $label) {
            $snapshot['sections'][0]['fields'][] = ['definition_id' => $key, 'key' => $key, 'label' => $label, 'type' => 'texto', 'legacy' => false, 'config' => [], 'value' => null];
        }
        $input = new DocumentRenderInput(
            subject: 'IA', subjectCode: 'SW-P7-037', academicPeriod: '2026', revisionNumber: 1,
            revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-09-07T20:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        );
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-ident-test-');
        file_put_contents($temporary, app(DocumentRenderer::class)->render($input)->docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);

        $this->assertSame(1, substr_count($xml, 'Tipo de discapacidad:'));
        $this->assertStringContainsString('Visual', $xml);
        $this->assertStringContainsString('Ingeniera de pruebas', $xml);
        $this->assertStringNotContainsString('Formación y experiencia<', $xml);
    }

    public function test_official_fixed_content_prints_in_word_and_inside_inputs_are_not_repeated(): void
    {
        $snapshot = $this->typedSnapshot();
        $field = fn (string $key, string $label, string $type, mixed $value, array $config = []): array => [
            'definition_id' => $key, 'key' => $key, 'label' => $label, 'type' => $type, 'legacy' => false, 'config' => $config, 'value' => $value,
        ];
        $snapshot['sections'][0]['fields'][] = $field('dato_en_tabla', 'Dato dentro de la tabla', 'texto', FreeText::fromText('Valor escrito dentro de la tabla'), ['inside' => 'identification_card']);
        $snapshot['sections'][] = ['key' => 'evaluacion', 'title' => 'EVALUACIÓN DE LOS APRENDIZAJES', 'fields' => [
            $field('indicadores', '', 'fijo', FixedContent::official('indicadores'), ['content' => FixedContent::official('indicadores')]),
            $field('escala', 'ESCALA DE VALORACIÓN', 'fijo', FixedContent::official('escala'), ['content' => FixedContent::official('escala')]),
        ], 'blocks' => []];
        $snapshot['sections'][] = ['key' => 'etica', 'title' => 'Conducta y comportamiento ético', 'fields' => [
            $field('etica', '', 'fijo', FixedContent::official('etica'), ['content' => FixedContent::official('etica')]),
        ], 'blocks' => []];
        $snapshot['sections'][] = ['key' => 'revision', 'title' => 'Revisión y aprobación', 'fields' => [
            $field('firmas', '', 'fijo', FixedContent::official('firmas'), ['content' => FixedContent::official('firmas')]),
        ], 'blocks' => []];
        $input = new DocumentRenderInput(
            subject: 'IA', subjectCode: 'SW-P7-037', academicPeriod: '2026', revisionNumber: 1,
            revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-09-07T20:00:00-05:00', locale: 'es-EC', snapshot: $snapshot,
        );
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-fixed-test-');
        file_put_contents($temporary, app(DocumentRenderer::class)->render($input)->docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);

        $this->assertStringNotContainsString('Dato dentro de la tabla', $xml);
        $this->assertStringNotContainsString('Valor escrito dentro de la tabla', $xml);
        foreach (['PRIMER PARCIAL', '2.5 PUNTOS', 'ESCALA CUALITATIVA', 'Recuperación', 'Puntualidad:', 'Acceso a la Video Conferencia:', 'Se considera inadmisible lo siguiente:', 'La falsificación de datos.', 'Fecha de entrega:', 'Fecha de revisión:'] as $text) {
            $this->assertStringContainsString($text, $xml, $text);
        }
        $this->assertStringContainsString('w:ilvl w:val="1"', $xml, 'La lista de lo inadmisible va anidada.');
        $this->assertStringContainsString('w:shd', $xml, 'Los encabezados de las tablas llevan sombreado.');
    }

    public function test_unnamed_part_prints_no_subtitle_and_titles_print_as_stored(): void
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
        $this->assertStringContainsString('Descripción', $document);
        $this->assertStringContainsString('Objetivos', $document);
        $this->assertStringNotContainsString('3.1 Descripción', $document);
        $this->assertStringNotContainsString('Campo', $document);
    }

    public function test_table_grids_match_cells_and_fill_the_usable_width(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['template_variables'] = TemplateVariables::resolve(['identification' => $snapshot['identification']]);
        $snapshot['sections'][0]['blocks'][0]['document'] = IdentificationCard::document($snapshot['identification']);
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
            $this->assertContains($total, [10542, 10541, 10540, 10539, 10538, 14142, 14141, 14140, 14139, 14138], 'La tabla no ocupa el ancho útil.');
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

    /**
     * @return array<string, mixed>
     */
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
        $sheet = fn (string $key, string $label): array => $this->legacySheet($key, $label);

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
        $snapshot['sections'][0]['blocks'][0]['document'] = IdentificationCard::document($snapshot['identification']);

        return $snapshot;
    }

    private function officialInput(): DocumentRenderInput
    {
        $variables = array_column(TemplateVariables::catalog(), 'sample', 'key');
        $sections = [];
        foreach (OfficialTemplate::sections() as $section) {
            $fields = [];
            foreach ($section['fields'] as $field) {
                $document = FieldDocument::normalize($field['content']);
                $fields[] = ['key' => $field['key'], 'label' => $field['label'] ?? '', 'quien_llena' => $field['quien_llena'], 'document' => $document, 'value' => FieldDocument::resolve($document, $variables)];
            }
            $sections[] = ['key' => $section['key'], 'title' => $section['title'], 'orientation' => $section['orientation'] ?? 'portrait', 'fields' => $fields];
        }

        return new DocumentRenderInput(
            subject: 'Asignatura de prueba', subjectCode: 'SW-TEST', academicPeriod: 'Período de prueba',
            revisionNumber: 1, revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-10-09T15:00:00Z',
            locale: 'es-EC', snapshot: ['schema_version' => 8, 'template_variables' => $variables, 'sections' => $sections],
        );
    }

    /** @return array{0: string, 1: DOMDocument, 2: DOMXPath} */
    private function documentXml(string $docx): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-document-test-');
        file_put_contents($temporary, $docx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        return [$xml, $dom, $xpath];
    }

    private function legacySheet(string $key, string $label = ''): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'field', 'attrs' => ['key' => $key, 'kind' => 'texto_libre']]]]]];
    }
}
