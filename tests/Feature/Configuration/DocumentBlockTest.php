<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\Blocks\BlockRules;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentBlockTest extends TestCase
{
    public function test_a_block_is_normalized_with_defaults(): void
    {
        $document = FieldDocument::normalize($this->identification());

        $this->assertSame('documentBlock', $document['content'][0]['type']);
        $this->assertSame(['disability' => ['answer' => null, 'type' => '', 'description' => '']], $document['content'][0]['attrs']['value']);
    }

    public function test_an_unknown_or_retired_kind_is_rejected(): void
    {
        foreach (['otro', 'si_no', 'repetible'] as $kind) {
            try {
                FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => ['kind' => $kind, 'props' => []]]]]);
                $this->fail("Se esperaba rechazar el bloque «{$kind}».");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_blocks_survive_the_html_round_trip(): void
    {
        $document = FieldDocument::normalize($this->identification(['disability' => ['answer' => 'si', 'type' => 'A "b"', 'description' => 'c & d']]));
        $lines = DocumentHtml::toLines($document);
        $back = FieldDocument::normalize(DocumentHtml::fromHtml($lines));

        $this->assertEquals($document, $back);
    }

    public function test_the_structure_check_only_compares_kind_and_props(): void
    {
        $template = FieldDocument::normalize($this->identification());
        $answered = FieldDocument::normalize($this->identification(['disability' => ['answer' => 'si']]));
        $edited = $answered;
        $edited['content'][0]['attrs']['kind'] = 'unidades';

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

    public function test_the_weeks_cannot_exceed_those_of_the_period(): void
    {
        $week = ['contenidos' => '', 'acd_h' => '', 'ape_h' => '', 'aa_h' => ''];
        $block = DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => array_fill(0, 4, ['nombre' => '', 'resultado' => '', 'semanas' => array_fill(0, 5, $week)])]]);
        $rules = OfficialTemplate::definition()->block('unidades')['rules'];

        $this->assertSame('Las unidades suman más semanas que el período.', BlockRules::failures($rules, $block['value'], ['semanas_periodo' => '16'], 'save')[0]['message']);
        $this->assertSame([], BlockRules::failures($rules, $block['value'], ['semanas_periodo' => '20'], 'save'));
    }

    public function test_the_teacher_declares_from_one_to_eight_units(): void
    {
        $unit = ['nombre' => 'U', 'resultado' => 'R', 'semanas' => []];
        $this->assertCount(3, DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => array_fill(0, 3, $unit)]])['value']['units']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('La planificación admite de 1 a 8 unidades.');
        DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => array_fill(0, 9, $unit)]]);
    }

    public function test_the_units_block_rejects_hours_that_are_not_numbers(): void
    {
        $this->expectException(ValidationException::class);
        DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => [['semanas' => [['acd_h' => 'tres']]]]]]);
    }

    public function test_every_unit_needs_at_least_one_week_to_submit(): void
    {
        $unit = fn (array $weeks): array => ['nombre' => 'U', 'resultado' => 'R', 'semanas' => $weeks];
        $block = DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => [$unit([['acd_h' => '2'], ['acd_h' => '2']]), $unit([])]]]);
        $variables = ['horas_docencia' => '4', 'horas_practicas' => '0', 'horas_autonomas' => '0', 'semanas_periodo' => '2'];

        $failures = DocumentBlock::failures(['attrs' => $block], $variables);
        $this->assertSame(['unidades_sin_semanas'], array_column($failures, 'code'));
        $this->assertSame('Cada unidad necesita al menos una semana: Unidades con semanas 1 de 2.', $failures[0]['message']);
        $this->assertSame([], DocumentBlock::failures(['attrs' => $block], $variables, 'save'));
    }

    public function test_the_unit_hours_are_added_per_component_and_compared_with_the_subject(): void
    {
        $block = DocumentBlock::normalize(['kind' => 'unidades', 'value' => ['units' => [['semanas' => [['acd_h' => '3', 'ape_h' => '1,5', 'aa_h' => ''], ['acd_h' => '2']]], ['semanas' => [['aa_h' => '4']]]]]]);
        $failures = DocumentBlock::failures(['attrs' => $block], ['horas_docencia' => '5', 'horas_practicas' => '2', 'horas_autonomas' => '4', 'semanas_periodo' => '16']);

        $this->assertSame(['unidades_sin_nombre', 'semanas_unidades_incompletas', 'horas_unidades_no_coinciden'], array_column($failures, 'code'));
        $this->assertSame('Escriba el nombre y el resultado de aprendizaje de cada unidad: Nombres 0 de 2, Resultados 0 de 2.', $failures[0]['message']);
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
