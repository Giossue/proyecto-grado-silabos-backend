<?php

namespace Tests\Unit\Configuration;

use App\Modules\Configuration\Domain\ResponseCondition;
use Tests\TestCase;

class ResponseConditionLinesTest extends TestCase
{
    private const IF_YES = ['response' => 'usa_lab', 'options' => ['o1']];

    /** @return array<string, mixed> */
    private function source(string $choice, ?string $id = 'usa_lab'): array
    {
        return ['type' => 'response', 'attrs' => array_filter(['kind' => 'una', 'options' => [['const' => 'o1', 'title' => 'Sí'], ['const' => 'o2', 'title' => 'No']], 'value' => $choice, 'id' => $id])];
    }

    /** @return array<string, mixed> */
    private function target(): array
    {
        return ['type' => 'response', 'attrs' => ['kind' => 'texto', 'options' => [], 'value' => 'Química', 'showIf' => self::IF_YES]];
    }

    /** @return array<string, mixed> */
    private function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  ...$content
     * @return array<string, mixed>
     */
    private function paragraph(array ...$content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * @param  array<string, mixed>  ...$content
     * @return array<string, mixed>
     */
    private function cell(array ...$content): array
    {
        return ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => $content];
    }

    public function test_a_hidden_response_hides_its_whole_line_unless_the_line_has_a_shown_response(): void
    {
        $document = ['type' => 'doc', 'content' => [
            $this->paragraph($this->text('¿Usa laboratorio? '), $this->source('o2')),
            $this->paragraph($this->text('Cuál: '), $this->target()),
            $this->paragraph($this->text('Turno: '), $this->source('o2', null), $this->text(' Cuál: '), $this->target()),
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->paragraph($this->text('Sala: '), $this->target())]]]],
            $this->paragraph($this->text('Texto fijo')),
        ]];

        $shown = ResponseCondition::withoutHiddenLines($document, ['usa_lab' => 'o2']);

        $this->assertSame([$document['content'][0], $document['content'][2], $document['content'][4]], $shown['content']);
        $this->assertSame($document, ResponseCondition::withoutHiddenLines($document, ['usa_lab' => 'o1']));
    }

    public function test_inside_a_table_the_cell_stays_and_only_its_content_is_hidden(): void
    {
        $table = fn (array $second): array => ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [$this->cell($this->paragraph($this->source('o2'))), $second]]]]]];
        $hidden = $this->cell($this->paragraph($this->text('Cuál:')), $this->paragraph($this->target()));

        $shown = ResponseCondition::withoutHiddenLines($table($hidden), ['usa_lab' => 'o2']);

        $this->assertSame([$this->cell($this->paragraph($this->source('o2'))), [...$hidden, 'content' => [['type' => 'paragraph']]]], $shown['content'][0]['content'][0]['content']);
        $this->assertSame($table($hidden), ResponseCondition::withoutHiddenLines($table($hidden), ['usa_lab' => 'o1']));
    }

    public function test_a_condition_whose_response_is_missing_still_shows_the_line(): void
    {
        $document = ['type' => 'doc', 'content' => [$this->paragraph($this->text('Cuál: '), $this->target())]];

        $this->assertSame($document, ResponseCondition::withoutHiddenLines($document, []));
    }
}
