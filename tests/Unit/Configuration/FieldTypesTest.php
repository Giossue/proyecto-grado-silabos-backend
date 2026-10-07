<?php

namespace Tests\Unit\Configuration;

use App\Modules\Configuration\Domain\FieldTypes\AutomaticFieldType;
use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
use App\Modules\Configuration\Domain\FieldTypes\FixedFieldType;
use App\Modules\Configuration\Domain\FieldTypes\ProfileFieldType;
use App\Modules\Configuration\Domain\FieldTypes\TableFieldType;
use App\Modules\Configuration\Domain\FieldTypes\TextFieldType;
use App\Modules\Configuration\Domain\FieldTypes\UnitsFieldType;
use App\Modules\Configuration\Domain\FreeText;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class FieldTypesTest extends TestCase
{
    private function table(): array
    {
        return [
            'columns' => [['key' => 'tema', 'label' => 'Tema'], ['key' => 'horas', 'label' => 'Horas']],
            'rows' => [['key' => 's1', 'label' => 'Semana 1'], ['key' => 's2', 'label' => 'Semana 2']],
        ];
    }

    public function test_registry_resolves_the_five_configured_types(): void
    {
        $registry = $this->app->make(FieldTypeRegistry::class);

        // Paridad con el frontend: al cambiar esta lista, actualice `registry.ts` y `registry.test.ts` (BACKEND_FIELD_TYPES).
        $this->assertSame(['texto', 'ficha', 'tabla', 'automatico', 'fijo', 'unidades'], $registry->keys());
        $this->assertInstanceOf(TableFieldType::class, $registry->get('tabla'));
    }

    public function test_only_the_general_types_can_be_chosen_by_hand(): void
    {
        $registry = $this->app->make(FieldTypeRegistry::class);

        $this->assertSame(['texto', 'ficha', 'tabla'], $registry->manualKeys());
        $this->assertFalse($registry->get('automatico')->manual());
        $this->assertFalse($registry->get('fijo')->manual());
    }

    public function test_registry_fails_clearly_for_unknown_type_and_duplicates(): void
    {
        $registry = new FieldTypeRegistry([new TextFieldType]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('«firma» no existe');
        try {
            new FieldTypeRegistry([new TextFieldType, new TextFieldType]);
            $this->fail('Se esperaba error por tipo repetido.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('dos veces', $e->getMessage());
        }
        $registry->get('firma');
    }

    public function test_text_type(): void
    {
        $type = new TextFieldType;

        $this->assertTrue($type->teacherEditable());
        $this->assertSame([], $type->normalizeConfig(['x' => 1]));
        $this->assertSame([], $type->normalizeConfig(['inside' => 'otra_fuente']));
        $this->assertSame(['inside' => 'identification_card'], $type->normalizeConfig(['inside' => 'identification_card']));
        $this->assertTrue($type->isEmpty($type->defaultValue([]), []));
        $value = $type->validateValue(FreeText::fromText('Hola'), []);
        $this->assertFalse($type->isEmpty($value, []));
        $this->expectException(ValidationException::class);
        $type->validateValue('texto plano', []);
    }

    public function test_profile_type_is_not_teacher_editable_and_normalizes_adjustable(): void
    {
        $type = new ProfileFieldType;

        $this->assertFalse($type->teacherEditable());
        $this->assertSame(['adjustable' => false], $type->normalizeConfig([]));
        $this->assertSame(['adjustable' => true], $type->normalizeConfig(['adjustable' => '1', 'otro' => 1]));
        $this->assertArrayHasKey('adjustable', $type->configRules());
        $this->assertTrue($type->isEmpty(null, []));
    }

    public function test_table_type_keeps_fixed_rows_and_columns(): void
    {
        $type = new TableFieldType;
        $config = $type->normalizeConfig($this->table());

        $default = $type->defaultValue($config);
        $this->assertSame(['tema' => '', 'horas' => ''], $default['s1']);
        $this->assertTrue($type->isEmpty($default, $config));

        $value = $type->validateValue(['s1' => ['tema' => ' Intro ']], $config);
        $this->assertSame('Intro', $value['s1']['tema']);
        $this->assertSame(['tema' => '', 'horas' => ''], $value['s2']);
        $this->assertFalse($type->isEmpty($value, $config));
    }

    public function test_table_type_rejects_extra_rows_cells_and_bad_config(): void
    {
        $type = new TableFieldType;
        $config = $type->normalizeConfig($this->table());

        foreach ([['s9' => ['tema' => 'x']], ['s1' => ['otra' => 'x']], 'texto'] as $bad) {
            try {
                $type->validateValue($bad, $config);
                $this->fail('Se esperaba ValidationException.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ValidationException::class);
        $type->normalizeConfig(['columns' => [['key' => 'a', 'label' => 'A'], ['key' => 'a', 'label' => 'B']], 'rows' => [['key' => 'r', 'label' => 'R']]]);
    }

    public function test_units_type_validates_units_and_weeks_and_totals_hours_per_unit(): void
    {
        $type = new UnitsFieldType;
        $this->assertSame([], $type->defaultValue([]));
        $this->assertTrue($type->isEmpty([], []));

        $week = fn (string $acd, string $text = 'Tema') => ['contenidos' => $text, 'acd_h' => $acd, 'ape_h' => '1', 'aa_h' => '3', 'acd' => 'Clase', 'ape' => 'Guía', 'aa' => 'Lectura', 'evaluacion' => 'Foro'];
        $value = $type->validateValue([
            ['nombre' => ' Fundamentos ', 'resultado' => 'Comprende', 'semanas' => [$week('2'), $week('2,5')]],
            ['nombre' => 'Segunda', 'resultado' => '', 'semanas' => [$week('2')]],
        ], []);
        $this->assertSame('Fundamentos', $value[0]['nombre']);
        $this->assertFalse($type->isEmpty($value, []));

        $week = 0;
        $table = $type->table($value[0], 1, $week);
        $this->assertSame(2, $week);
        $total = collect($table['content'])->last()['content'];
        $this->assertSame('Total, horas', $total[0]['content'][0]['content'][0]['text']);
        $this->assertSame('4.5', $total[1]['content'][0]['content'][0]['text']);
        $this->assertSame('2', $total[2]['content'][0]['content'][0]['text']);
        $this->assertSame('6', $total[3]['content'][0]['content'][0]['text']);
        $second = $type->table($value[1], 2, $week);
        $this->assertSame(3, $week);
        $this->assertSame('3', $second['content'][5]['content'][1]['content'][0]['content'][0]['text']);
    }

    public function test_units_type_rejects_bad_hours_and_more_than_sixteen_weeks(): void
    {
        $type = new UnitsFieldType;
        $week = ['contenidos' => 'x', 'acd_h' => '2', 'ape_h' => '1', 'aa_h' => '3', 'acd' => '', 'ape' => '', 'aa' => '', 'evaluacion' => ''];
        foreach ([
            [['nombre' => 'U', 'resultado' => '', 'semanas' => [[...$week, 'acd_h' => 'dos']]]],
            [['nombre' => 'U', 'resultado' => '', 'semanas' => array_fill(0, 17, $week)]],
            'texto',
        ] as $bad) {
            try {
                $type->validateValue($bad, []);
                $this->fail('Se esperaba ValidationException.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fixed_type_keeps_institutional_content_and_stores_no_value(): void
    {
        $type = new FixedFieldType;
        $content = FreeText::fromText('Texto institucional');
        $config = $type->normalizeConfig(['content' => $content]);

        $this->assertSame(['content' => $content], $config);
        $this->assertFalse($type->teacherEditable());
        $this->assertSame($content, $type->defaultValue($config));
        $this->assertSame($content, $type->toSnapshot(null, $config, []));
        $this->assertNull($type->validateValue(null, $config));
        $this->assertFalse($type->isEmpty(null, $config));
        foreach ([null, 'texto', FreeText::fromText('  ')] as $bad) {
            try {
                $type->normalizeConfig(['content' => $bad]);
                $this->fail('Se esperaba ValidationException.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ValidationException::class);
        $type->validateValue($content, $config);
    }

    public function test_automatic_type_stores_no_value(): void
    {
        $type = new AutomaticFieldType;

        $this->assertSame(['source' => 'identification_card'], $type->normalizeConfig(['source' => 'identification_card']));
        $this->assertNull($type->defaultValue([]));
        $this->assertNull($type->validateValue(null, []));
        $this->assertFalse($type->isEmpty(null, []));
        $this->assertFalse($type->teacherEditable());
        $this->expectException(ValidationException::class);
        $type->normalizeConfig(['source' => 'inexistente']);
    }

    public function test_automatic_type_rejects_a_stored_value(): void
    {
        $this->expectException(ValidationException::class);
        (new AutomaticFieldType)->validateValue('algo', []);
    }
}
