<?php

namespace Tests\Unit\Academic;

use App\Modules\Academic\Application\ExtractFichaProposal;
use App\Modules\Academic\Application\FichaEvidence;
use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Domain\Contracts\FichaExtractionGateway;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExtractFichaProposalTest extends TestCase
{
    public function test_it_searches_again_for_missing_fields_without_overwriting_the_first_proposal(): void
    {
        $gateway = Mockery::mock(FichaExtractionGateway::class);
        $gateway->shouldReceive('propose')->once()->ordered()->andReturn(['descripcion' => 'Descripción original']);
        $gateway->shouldReceive('propose')->once()->ordered()->withArgs(fn (array $subject, array $fields, array $evidence): bool => array_column($fields, 'key') === ['objetivo'] && str_contains(implode(' ', $evidence), 'Objetivo general'))->andReturn(['descripcion' => 'No debe reemplazarse', 'objetivo' => 'Resolver sistemas']);
        $fields = [['key' => 'descripcion', 'label' => 'Descripción', 'columns' => []], ['key' => 'objetivo', 'label' => 'Objetivo general', 'columns' => []]];

        $result = (new ExtractFichaProposal(new FichaEvidence, new FichaFieldValues, $gateway))->execute(['name' => 'Álgebra Lineal', 'code' => 'MAT-01', 'cycle' => 1], $fields, ['Álgebra Lineal', 'Descripción: matrices.', 'Objetivo general: resolver sistemas.'], []);

        $this->assertSame(['descripcion' => 'Descripción original', 'objetivo' => 'Resolver sistemas'], (array) $result['values']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(2, $result['extraction']['attempts']);
    }

    public function test_a_failed_recovery_keeps_found_values_and_explains_the_missing_field(): void
    {
        $gateway = Mockery::mock(FichaExtractionGateway::class);
        $gateway->shouldReceive('propose')->once()->ordered()->andReturn(['descripcion' => 'Matrices']);
        $gateway->shouldReceive('propose')->once()->ordered()->andThrow(new RuntimeException('Error interno sensible'));
        $fields = [['key' => 'descripcion', 'label' => 'Descripción', 'columns' => []], ['key' => 'objetivo', 'label' => 'Objetivo general', 'columns' => []]];

        $result = (new ExtractFichaProposal(new FichaEvidence, new FichaFieldValues, $gateway))->execute(['name' => 'Álgebra Lineal', 'code' => 'MAT-01', 'cycle' => 1], $fields, ['Álgebra Lineal y matrices'], []);

        $this->assertSame('lista', $result['state']);
        $this->assertSame(['descripcion' => 'Matrices'], (array) $result['values']);
        $this->assertSame('servicio_no_disponible', $result['missing'][0]['reason']);
        $this->assertStringNotContainsString('sensible', json_encode($result));
    }

    public function test_the_recovery_budget_keeps_relevant_late_sections_before_unrelated_filler(): void
    {
        $fields = [['key' => 'descripcion', 'label' => 'Descripción', 'columns' => []]];
        foreach (['Objetivo general', 'Habilidades', 'Resultados', 'Perfil', 'Objetivos específicos'] as $index => $label) {
            $fields[] = ['key' => 'campo'.$index, 'label' => $label, 'columns' => []];
        }
        $blocks = ['Álgebra Lineal'];
        for ($index = 0; $index < 25; $index++) {
            $blocks[] = str_repeat('Contenido extenso de la materia. ', 60);
        }
        $blocks[] = 'Objetivo general: resolver sistemas lineales.';
        $gateway = Mockery::mock(FichaExtractionGateway::class);
        $gateway->shouldReceive('propose')->once()->ordered()->andReturn(['descripcion' => 'Matrices']);
        $gateway->shouldReceive('propose')->once()->ordered()->withArgs(fn (array $subject, array $fields, array $evidence): bool => str_contains(implode(' ', $evidence), 'Objetivo general: resolver sistemas lineales.'))->andReturn(['campo0' => 'Resolver sistemas lineales']);

        $result = (new ExtractFichaProposal(new FichaEvidence, new FichaFieldValues, $gateway))->execute(['name' => 'Álgebra Lineal', 'code' => 'MAT-01', 'cycle' => 1], $fields, $blocks, []);

        $this->assertSame('Resolver sistemas lineales', ((array) $result['values'])['campo0']);
    }

    public function test_without_subject_context_it_does_not_call_the_provider(): void
    {
        $gateway = Mockery::mock(FichaExtractionGateway::class);
        $gateway->shouldNotReceive('propose');

        $result = (new ExtractFichaProposal(new FichaEvidence, new FichaFieldValues, $gateway))->execute(['name' => 'Álgebra Lineal', 'code' => 'MAT-01', 'cycle' => 1], [['key' => 'descripcion', 'label' => 'Descripción', 'columns' => []]], ['Geometría y figuras.'], []);

        $this->assertSame('sin_evidencia', $result['state']);
        $this->assertSame('sin_contexto', $result['missing'][0]['reason']);
        $this->assertSame(0, $result['extraction']['attempts']);
    }
}
