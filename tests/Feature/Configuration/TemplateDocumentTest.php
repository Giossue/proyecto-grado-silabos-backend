<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TemplateDocumentTest extends TestCase
{
    public function test_text_uses_the_global_content_size(): void
    {
        $text = fn (string $value): array => [
            'type' => 'text',
            'text' => $value,
            'marks' => [[
                'type' => 'textStyle',
                'attrs' => [
                    'fontFamily' => 'Times New Roman',
                    'fontSize' => '7pt',
                    'color' => '#000000',
                ],
            ]],
        ];
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [$text('Fuera')]],
            ['type' => 'table', 'content' => [[
                'type' => 'tableRow',
                'content' => [[
                    'type' => 'tableCell',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [$text('Dentro')],
                    ]],
                ]],
            ]]],
        ]];

        $normalized = TemplateDocument::normalize($document, []);
        $texts = TemplateDocument::nodes($normalized, 'text');

        $this->assertSame('Times New Roman', $texts[0]['marks'][0]['attrs']['fontFamily']);
        $this->assertArrayNotHasKey('fontSize', $texts[0]['marks'][0]['attrs']);
        $this->assertArrayNotHasKey('fontSize', $texts[1]['marks'][0]['attrs']);
        $this->assertArrayNotHasKey('fontFamily', $texts[1]['marks'][0]['attrs']);
        $this->assertSame('#000000', $texts[1]['marks'][0]['attrs']['color']);
    }

    public function test_generic_document_preserves_lists_merged_cells_and_styles(): void
    {
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Actividad']]]]]]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['colspan' => 2, 'rowspan' => 2, 'backgroundColor' => '#4F81BD'], 'content' => [['type' => 'paragraph']]]]],
                ['type' => 'tableRow'],
            ]],
        ]];
        $normalized = FieldDocument::normalize($document);
        $this->assertCount(2, TemplateDocument::nodes($normalized, 'tableRow'));
        $this->assertSame(2, TemplateDocument::nodes($normalized, 'tableCell')[0]['attrs']['rowspan']);
        $this->assertSame('#4F81BD', TemplateDocument::nodes($normalized, 'tableCell')[0]['attrs']['backgroundColor']);
        $this->assertStringContainsString('Actividad', FreeText::plainText($normalized));
    }

    public function test_invalid_merges_are_rejected(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['rowspan' => 2], 'content' => [['type' => 'paragraph']]]]],
        ]]]];
        $this->expectException(ValidationException::class);
        FieldDocument::normalize($document);
    }

    public function test_variables_resolve_inside_any_table_or_list(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'nombre_asignatura']]]]]]]]]];
        $resolved = FieldDocument::resolve(FieldDocument::normalize($document), ['nombre_asignatura' => 'Calidad de Software']);
        $this->assertStringContainsString('Calidad de Software', FreeText::plainText($resolved));
        $this->assertSame([], TemplateDocument::nodes($resolved, 'variable'));
    }

    public function test_hostile_nodes_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'script', 'text' => 'alert(1)']]]);
    }
}
