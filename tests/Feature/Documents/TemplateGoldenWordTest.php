<?php

namespace Tests\Feature\Documents;

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use Tests\TestCase;
use ZipArchive;

class TemplateGoldenWordTest extends TestCase
{
    private const MAPPING = [
        'appearance' => ['margin_cm' => 1.5, 'text_color' => '#000000', 'title_bold' => true, 'font_family' => 'Arial', 'orientation' => 'portrait', 'accent_color' => '#2F5496', 'section_bold' => true, 'title_italic' => false, 'body_alignment' => 'left', 'body_font_size' => 11, 'section_italic' => false, 'field_font_size' => 11, 'title_alignment' => 'center', 'title_font_size' => 14, 'section_alignment' => 'left', 'section_font_size' => 11, 'table_header_color' => '#FFFFFF', 'table_header_background' => '#4F81BD'],
        'title_block' => ['text' => 'PROGRAMA DE ASIGNATURA (SÍLABO)'],
        'layout' => ['margin_cm' => 1.5, 'title_font_size' => 14, 'section_font_size' => 11, 'body_font_size' => 11],
    ];

    public function test_official_template_renders_the_same_word_document(): void
    {
        $this->assertGolden('oficial', $this->officialInput([]));
    }

    public function test_official_template_without_practice_renders_the_same_word_document(): void
    {
        $this->assertGolden('oficial-sin-practicas', $this->officialInput(['horas_practicas' => '0']));
    }

    public function test_question_and_list_blocks_render_the_same_word_document(): void
    {
        $block = fn (string $kind, array $props, array $value): string => '<div data-block="'.$kind.'" data-props=\''.json_encode($props, JSON_UNESCAPED_UNICODE).'\' data-value=\''.json_encode($value, JSON_UNESCAPED_UNICODE).'\'></div>';
        $columns = [['key' => 'autor', 'label' => 'Autor'], ['key' => 'titulo', 'label' => 'Título']];
        $content = [
            '<p>Antes de los bloques.</p>',
            $block('si_no', ['question' => '¿Usa laboratorio?', 'columns' => $columns], ['answer' => 'si', 'rows' => [['autor' => 'Ana', 'titulo' => 'Redes'], ['autor' => 'Luis', 'titulo' => 'Datos']]]),
            $block('si_no', ['question' => '¿Tiene salidas de campo?', 'columns' => []], ['answer' => 'no', 'rows' => []]),
            $block('si_no', ['question' => '¿Sin responder?', 'columns' => $columns], ['answer' => null, 'rows' => []]),
            $block('repetible', ['label' => 'Lecturas', 'columns' => $columns], ['rows' => [['autor' => 'Eva', 'titulo' => 'Algoritmos']]]),
            $block('repetible', ['label' => '', 'columns' => $columns], ['rows' => []]),
        ];
        $variables = array_column(TemplateVariables::catalog(), 'sample', 'key');
        $document = FieldDocument::normalize(DocumentHtml::fromHtml($content));
        $sections = [['key' => 'bloques', 'title' => 'Bloques', 'orientation' => 'portrait', 'fields' => [
            ['key' => 'bloques', 'label' => 'Bloques', 'quien_llena' => 'docente', 'document' => $document, 'value' => FieldDocument::resolve($document, $variables)],
        ]]];

        $this->assertGolden('bloques', $this->input($sections, $variables));
    }

    /** @param  array<string, string>  $overrides */
    private function officialInput(array $overrides): DocumentRenderInput
    {
        $variables = [...array_column(TemplateVariables::catalog(), 'sample', 'key'), ...$overrides];
        $sections = [];
        foreach (OfficialTemplate::sections() as $section) {
            $fields = [];
            foreach ($section['fields'] as $field) {
                $document = $this->filled(FieldDocument::normalize($field['content']));
                $fields[] = ['key' => $field['key'], 'label' => $field['label'] ?? '', 'quien_llena' => $field['quien_llena'], 'document' => $document, 'value' => FieldDocument::resolve($document, $variables)];
            }
            $sections[] = ['key' => $section['key'], 'title' => $section['title'], 'orientation' => $section['orientation'] ?? 'portrait', 'fields' => $fields];
        }

        return $this->input($sections, $variables);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function filled(array $node): array
    {
        if (($node['type'] ?? null) === 'documentBlock' && $node['attrs']['kind'] === 'unidades') {
            $units = [];
            for ($unit = 1; $unit <= 4; $unit++) {
                $weeks = [];
                for ($week = 1; $week <= 4; $week++) {
                    $weeks[] = ['contenidos' => "Contenido {$unit}.{$week}", 'acd_h' => '2', 'ape_h' => $week === 1 ? '1,5' : '1', 'aa_h' => '1', 'acd' => "Clase {$unit}.{$week}", 'ape' => 'Laboratorio', 'aa' => 'Lectura', 'evaluacion' => $week === 4 ? 'Prueba' : ''];
                }
                $units[] = ['nombre' => "Unidad de prueba {$unit}", 'resultado' => "Resultado {$unit}", 'semanas' => $weeks];
            }
            $node['attrs']['value'] = ['units' => $units];
        }
        if (($node['type'] ?? null) === 'documentBlock' && $node['attrs']['kind'] === 'identificacion') {
            $node['attrs']['value'] = ['disability' => ['answer' => 'si', 'type' => 'Visual', 'description' => 'Baja visión']];
        }
        if (isset($node['content'])) {
            $node['content'] = array_map(fn (array $child): array => $this->filled($child), $node['content']);
        }

        return $node;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, string>  $variables
     */
    private function input(array $sections, array $variables): DocumentRenderInput
    {
        return new DocumentRenderInput(
            subject: 'Asignatura de prueba', subjectCode: 'SW-TEST', academicPeriod: 'Período de prueba',
            revisionNumber: 1, revisionFingerprint: str_repeat('ab', 32), generatedAt: '2026-10-19T15:00:00Z',
            locale: 'es-EC', snapshot: ['schema_version' => 8, 'document_mapping' => self::MAPPING, 'template_variables' => $variables, 'sections' => $sections],
        );
    }

    private function assertGolden(string $name, DocumentRenderInput $input): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-golden-');
        file_put_contents($temporary, app(DocumentRenderer::class)->render($input)->docx->bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);
        $xml = (string) preg_replace('/(w:id|r:id|w:name|wp14:anchorId|wp14:editId|w14:paraId|w14:textId|id|name)="[^"]*"/', '$1=""', $xml);
        $xml = (string) preg_replace('#<w:r>(<w:rPr>(?:(?!</w:rPr>).)*</w:rPr>|<w:rPr/>)?<w:t xml:space="preserve"/></w:r>#', '', $xml);
        $xml = (string) preg_replace('#<w:r>(<w:rPr>(?:(?!</w:rPr>).)*</w:rPr>|<w:rPr/>)?<w:t xml:space="preserve"></w:t></w:r>#', '', $xml);
        $path = base_path("tests/Fixtures/golden/{$name}.xml");
        if (getenv('UPDATE_GOLDEN') === '1') {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $xml);
        }

        $this->assertFileExists($path);
        $this->assertSame(file_get_contents($path), $xml, "El Word «{$name}» cambió respecto de la referencia.");
    }
}
