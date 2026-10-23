<?php

namespace Tests\Unit\Academic;

use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlockRecord;
use Tests\TestCase;

class FichaFieldValuesTest extends TestCase
{
    public function test_merged_table_headers_define_the_columns_and_rows_are_saved_as_a_table(): void
    {
        $cell = fn (string $text, array $attrs = []): array => ['type' => 'tableHeader', 'attrs' => $attrs, 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
        $field = new FieldDefinition(['contenido' => ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [$cell('Resultado', ['rowspan' => 2]), $cell('Nivel', ['colspan' => 2])]],
            ['type' => 'tableRow', 'content' => [$cell('Alto'), $cell('Medio')]],
        ]]]]]);
        $field->setRelation('blockRecord', (new TemplateBlockRecord)->forceFill(['clave_campo_plantilla' => 'relacion', 'titulo_campo_plantilla' => 'Relación de resultados']));
        $values = new FichaFieldValues;
        $columns = $values->schema($field)['columns'];

        $this->assertSame(['Resultado', 'Nivel / Alto', 'Nivel / Medio'], $columns);
        $clean = $values->clean(['columns' => ['Inventada'], 'rows' => [['Resuelve sistemas', 'X', '']]], $columns);
        $document = $values->document($clean, $field->contenido);
        $this->assertSame('table', $document['content'][0]['type']);
        $this->assertStringContainsString('Alto | Medio', FreeText::plainText($document));
        $this->assertStringContainsString('Resuelve sistemas', FreeText::plainText($document));
        $this->assertStringNotContainsString('Inventada', FreeText::plainText($document));
        $this->assertSame(2, $document['content'][0]['content'][0]['content'][1]['attrs']['colspan']);
        $this->assertCount(3, $document['content'][0]['content']);
    }

    public function test_it_rejects_malformed_tables_and_keeps_missing_cells_empty(): void
    {
        $values = new FichaFieldValues;
        $this->assertNull($values->clean(['rows' => [['Resultado']]], ['Resultado', 'Nivel']));
        $this->assertNull($values->clean(['rows' => [['Resultado', ['Nivel' => 'Alto']]]], ['Resultado', 'Nivel']));
        $this->assertNull($values->clean('Relación sin estructura', ['Resultado', 'Nivel']));
        $this->assertSame(['columns' => ['Resultado', 'Nivel'], 'rows' => [['Resultado', '']]], $values->clean(['rows' => [['Resultado', '']]], ['Resultado', 'Nivel']));
        $this->assertNull($values->clean(['rows' => []], ['Resultado', 'Nivel']));
        $this->assertSame(['Analizar', 'Aplicar'], $values->clean(['Analizar', 'Aplicar'], []));
    }
}
