<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentBlockTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function disability(array $value = []): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => [
            'kind' => 'si_no',
            'props' => ['question' => '¿Hay estudiantes con discapacidad?', 'columns' => [['key' => 'tipo', 'label' => 'Tipo'], ['key' => 'detalle', 'label' => 'Breve descripción']]],
            'value' => $value,
        ]]]];
    }

    public function test_a_block_is_normalized_with_defaults(): void
    {
        $document = FieldDocument::normalize($this->disability());

        $this->assertSame('documentBlock', $document['content'][0]['type']);
        $this->assertSame(['answer' => null, 'rows' => []], $document['content'][0]['attrs']['value']);
    }

    public function test_an_unanswered_block_counts_as_empty_and_an_answered_one_does_not(): void
    {
        $this->assertTrue(FieldDocument::isEmpty(FieldDocument::normalize($this->disability())));
        $this->assertFalse(FieldDocument::isEmpty(FieldDocument::normalize($this->disability(['answer' => 'no']))));
    }

    public function test_rows_only_survive_when_the_answer_is_yes_and_unknown_columns_are_dropped(): void
    {
        $yes = FieldDocument::normalize($this->disability(['answer' => 'si', 'rows' => [['tipo' => 'Visual', 'detalle' => 'Baja visión', 'otro' => 'x']]]));
        $this->assertSame([['tipo' => 'Visual', 'detalle' => 'Baja visión']], $yes['content'][0]['attrs']['value']['rows']);

        $no = FieldDocument::normalize($this->disability(['answer' => 'no', 'rows' => [['tipo' => 'Visual', 'detalle' => 'x']]]));
        $this->assertSame([], $no['content'][0]['attrs']['value']['rows']);
    }

    public function test_the_block_expands_into_ordinary_nodes_for_word_and_pdf(): void
    {
        $document = FieldDocument::normalize($this->disability(['answer' => 'si', 'rows' => [['tipo' => 'Visual', 'detalle' => 'Baja visión']]]));
        $resolved = TemplateDocumentResolver::resolve($document, [], []);

        $this->assertSame('paragraph', $resolved['content'][0]['type']);
        $this->assertStringContainsString('Sí', FreeText::plainText($resolved));
        $this->assertSame('table', $resolved['content'][1]['type']);
        $this->assertCount(2, $resolved['content'][1]['content']);
        $this->assertStringContainsString('Baja visión', FreeText::plainText($resolved));
    }

    public function test_a_no_answer_hides_the_table(): void
    {
        $resolved = TemplateDocumentResolver::resolve(FieldDocument::normalize($this->disability(['answer' => 'no'])), [], []);

        $this->assertCount(1, $resolved['content']);
    }

    public function test_a_repeatable_list_needs_columns_and_valid_keys(): void
    {
        $this->expectException(ValidationException::class);
        FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => ['kind' => 'repetible', 'props' => ['columns' => []]]]]]);
    }

    public function test_an_unknown_kind_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => ['kind' => 'otro', 'props' => []]]]]);
    }

    public function test_blocks_survive_the_html_round_trip(): void
    {
        $document = FieldDocument::normalize($this->disability(['answer' => 'si', 'rows' => [['tipo' => 'A "b"', 'detalle' => 'c & d']]]));
        $lines = DocumentHtml::toLines($document);
        $back = FieldDocument::normalize(DocumentHtml::fromHtml($lines));

        $this->assertEquals($document, $back);
    }

    public function test_the_structure_check_only_compares_kind_and_props(): void
    {
        $template = FieldDocument::normalize($this->disability());
        $answered = FieldDocument::normalize($this->disability(['answer' => 'si']));
        $edited = $answered;
        $edited['content'][0]['attrs']['props']['question'] = 'Otra pregunta';

        $this->assertTrue(DocumentBlock::sameStructure($template, $answered));
        $this->assertFalse(DocumentBlock::sameStructure($template, $edited));
        $this->assertFalse(DocumentBlock::sameStructure($template, FieldDocument::empty()));
    }
}
