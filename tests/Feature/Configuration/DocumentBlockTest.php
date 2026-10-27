<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;
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

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function identification(array $value = []): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => ['kind' => 'identificacion', 'props' => [], 'value' => $value]]]];
    }

    public function test_the_identification_block_is_one_table_with_the_system_data_and_the_teacher_answer(): void
    {
        $variables = array_column(TemplateVariables::catalog(), 'sample', 'key');
        $answered = FieldDocument::normalize($this->identification(['disability' => ['answer' => 'si', 'type' => 'Visual', 'description' => 'Baja visión']]));
        $resolved = TemplateDocumentResolver::resolve($answered, [], [...$variables, 'formacion_docente' => 'Ingeniera en Sistemas']);

        $this->assertCount(1, $resolved['content']);
        $this->assertSame('table', $resolved['content'][0]['type']);
        $text = FreeText::plainText($resolved);
        foreach (['FACULTAD:', 'ESTUDIANTES CON DISCAPACIDAD', 'Tipo de discapacidad: Visual', 'Breve descripción: Baja visión', 'FORMACIÓN Y EXPERIENCIA ACADÉMICA', 'Ingeniera en Sistemas'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_the_identification_answer_marks_yes_or_no_and_clears_the_detail_when_it_is_no(): void
    {
        $no = FieldDocument::normalize($this->identification(['disability' => ['answer' => 'no', 'type' => 'Visual', 'description' => 'x']]));
        $this->assertSame(['answer' => 'no', 'type' => '', 'description' => ''], $no['content'][0]['attrs']['value']['disability']);
        $text = FreeText::plainText(TemplateDocumentResolver::resolve($no, [], []));
        $this->assertMatchesRegularExpression('/No \| X/', $text);
        $this->assertStringNotContainsString('Visual', $text);
        $this->assertTrue(FieldDocument::isEmpty(FieldDocument::normalize($this->identification())));
        $this->assertFalse(FieldDocument::isEmpty($no));
    }

    public function test_the_identification_layout_comes_from_the_template_with_catalog_data_and_the_teacher_answer(): void
    {
        $layout = ['type' => 'doc', 'content' => OfficialTemplate::definition()->block('identificacion')['layout']];
        $variables = array_column(array_column(TemplateDocument::nodes($layout, 'variable'), 'attrs'), 'id');
        $slots = array_column(array_column(TemplateDocument::nodes($layout, 'slot'), 'attrs'), 'value');

        $this->assertSame([], array_diff($variables, array_keys(TemplateVariables::definitions())));
        $this->assertEqualsCanonicalizing(['disability.answer', 'disability.answer', 'disability.type', 'disability.description'], $slots);
        $resolved = TemplateDocumentResolver::resolve(FieldDocument::normalize($this->identification()), [], ['nombre_facultad' => 'Ciencias', 'formacion_docente' => 'Formación escrita']);
        $this->assertStringContainsString('FACULTAD: Ciencias', FreeText::plainText($resolved));
        $this->assertStringContainsString('Formación escrita', FreeText::plainText($resolved));
    }

    public function test_the_units_block_has_four_units_sharing_sixteen_weeks_and_expands_to_one_table_each(): void
    {
        $block = DocumentBlock::normalize(['kind' => 'unidades']);
        $this->assertCount(4, $block['value']['units']);
        $this->assertSame([4, 4, 4, 4], array_map(fn (array $unit): int => count($unit['semanas']), $block['value']['units']));

        $tables = array_values(array_filter(DocumentBlock::expand(['attrs' => $block]), fn (array $node): bool => $node['type'] === 'table'));
        $this->assertCount(4, $tables);
        $this->assertSame('16', FreeText::plainText($tables[3]['content'][5 + 3]['content'][1]));
    }

    public function test_the_units_block_rejects_more_than_sixteen_weeks_and_text_as_hours(): void
    {
        $week = ['contenidos' => '', 'acd_h' => '', 'ape_h' => '', 'aa_h' => ''];
        $units = array_fill(0, 4, ['nombre' => '', 'resultado' => '', 'semanas' => array_fill(0, 5, $week)]);
        $this->expectException(ValidationException::class);
        DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => $units]]);
    }

    public function test_the_units_block_rejects_hours_that_are_not_numbers(): void
    {
        $this->expectException(ValidationException::class);
        DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => [['semanas' => [['acd_h' => 'tres']]]]]]);
    }

    public function test_the_unit_hours_are_added_per_component_and_compared_with_the_subject(): void
    {
        $block = DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => [['semanas' => [['acd_h' => '3', 'ape_h' => '1,5', 'aa_h' => ''], ['acd_h' => '2']]], ['semanas' => [['aa_h' => '4']]]]]]);
        $failures = DocumentBlock::failures(['attrs' => $block], ['horas_docencia' => '5', 'horas_practicas' => '2', 'horas_autonomas' => '4']);

        $this->assertSame(['unidades_sin_nombre', 'semanas_unidades_incompletas', 'horas_unidades_no_coinciden'], array_column($failures, 'code'));
        $this->assertSame('Escriba el nombre y el resultado de aprendizaje de las 4 unidades: Nombres 0 de 4, Resultados 0 de 4.', $failures[0]['message']);
        $this->assertSame('Las horas de las unidades deben sumar las de la asignatura: APE 1.5 de 2.', $failures[2]['message']);
        $this->assertSame('Las unidades deben repartir las 16 semanas del período: tienen 3.', $failures[1]['message']);
    }

    public function test_the_teacher_names_each_unit_and_writes_its_result_before_sending(): void
    {
        $unit = fn (string $name, string $result): array => ['nombre' => $name, 'resultado' => $result, 'semanas' => [['acd_h' => '1']]];
        $codes = fn (array $units): array => array_column(DocumentBlock::failures(['attrs' => DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => $units]])], ['horas_docencia' => '4', 'horas_practicas' => '0', 'horas_autonomas' => '0']), 'code');

        $this->assertContains('unidades_sin_nombre', $codes([$unit('Uno', 'R1'), $unit('Dos', 'R2'), $unit('Tres', '  '), $unit('', 'R4')]));
        $this->assertNotContains('unidades_sin_nombre', $codes([$unit('Uno', 'R1'), $unit('Dos', 'R2'), $unit('Tres', 'R3'), $unit('Cuatro', 'R4')]));
    }

    public function test_the_evaluation_points_follow_the_practical_hours_of_the_subject(): void
    {
        $text = fn (array $variables): string => FreeText::plainText(['type' => 'doc', 'content' => DocumentBlock::expand(['attrs' => ['kind' => 'evaluacion']], $variables)]);

        $with = $text(['horas_practicas' => '16']);
        $this->assertStringContainsString('2.5 PUNTOS', $with);
        $this->assertStringContainsString('práctico-experimental', $with);

        $without = $text(['horas_practicas' => '0']);
        $this->assertStringContainsString('3.5 PUNTOS', $without);
        $this->assertStringContainsString('3.0 PUNTOS', $without);
        $this->assertStringNotContainsString('práctico-experimental', $without);
    }
}
