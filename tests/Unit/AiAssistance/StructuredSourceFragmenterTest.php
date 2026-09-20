<?php

namespace Tests\Unit\AiAssistance;

use App\Modules\AiAssistance\Application\StructuredSourceFragmenter;
use PHPUnit\Framework\TestCase;

final class StructuredSourceFragmenterTest extends TestCase
{
    public function test_fragments_document_by_heading_and_keeps_page_provenance(): void
    {
        $document = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Resultados']]],
                ['type' => 'paragraph', 'attrs' => ['sourcePage' => 3, 'sourceOrder' => 1], 'content' => [['type' => 'text', 'text' => 'Diseña software seguro.']]],
                ['type' => 'table', 'attrs' => ['sourcePage' => 4, 'sourceOrder' => 2], 'content' => [[
                    'type' => 'tableRow',
                    'content' => [
                        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nivel']]]]],
                        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Avanzado']]]]],
                    ],
                ]]],
            ],
        ];

        $fragments = (new StructuredSourceFragmenter)->fragment($document);

        self::assertCount(1, $fragments);
        self::assertSame(['Resultados'], $fragments[0]['heading_path']);
        self::assertSame(3, $fragments[0]['page_start']);
        self::assertSame(4, $fragments[0]['page_end']);
        self::assertStringContainsString('Avanzado', $fragments[0]['content']);
        self::assertSame('table', $fragments[0]['source_blocks'][1]['type']);
    }
}
