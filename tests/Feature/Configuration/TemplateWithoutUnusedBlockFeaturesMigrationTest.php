<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TemplateWithoutUnusedBlockFeaturesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_template_drops_retired_blocks_from_and_not(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->storeDefinition(function (array $definition): array {
            $definition['blocks']['si_no'] = ['label' => 'Pregunta Sí/No', 'value' => [], 'layout' => []];
            $definition['blocks']['repetible'] = ['label' => 'Lista', 'value' => [], 'layout' => []];
            $definition['blocks']['unidades']['value']['units']['item']['fields']['nombre']['from'] = ['field' => 'unidades_curriculares', 'column' => 1];
            $definition['blocks']['evaluacion']['layout'][0]['attrs']['when'] = ['not' => ['variable' => 'horas_practicas', 'zero' => true]];

            return $definition;
        });

        $this->migration()->up();

        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $this->assertSame(['identificacion', 'unidades', 'evaluacion'], array_keys($definition['blocks']));
        $this->assertArrayNotHasKey('from', $definition['blocks']['unidades']['value']['units']['item']['fields']['nombre']);
        $this->assertSame(['variable' => 'horas_practicas', 'zero' => false], $definition['blocks']['evaluacion']['layout'][0]['attrs']['when']);
    }

    public function test_it_stops_when_a_field_still_uses_a_retired_block(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        DB::table('definiciones_campo')->limit(1)->update(['contenido_definicion_campo' => json_encode(['type' => 'doc', 'content' => [['type' => 'documentBlock', 'attrs' => ['kind' => 'si_no', 'props' => [], 'value' => []]]]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Un campo usa los bloques «si_no» o «repetible»');
        $this->migration()->up();
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function storeDefinition(callable $change): void
    {
        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($change($definition), JSON_UNESCAPED_UNICODE)]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_27_000242_template_without_unused_block_features.php');
    }
}
