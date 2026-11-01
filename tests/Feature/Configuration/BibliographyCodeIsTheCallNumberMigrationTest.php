<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BibliographyCodeIsTheCallNumberMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_block_links_the_code_column_to_the_call_number(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->storeDefinition(function (array $definition): array {
            unset($definition['blocks']['bibliografia']['catalog']['map']['call_number']);

            return $definition;
        });
        $before = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->up();

        $this->assertSame('codigo', $this->definition()['blocks']['bibliografia']['catalog']['map']['call_number']);
        $this->assertEquals($before, DB::table('plantilla_silabo')->value('plantilla_modificada_en'));
    }

    public function test_a_block_without_the_code_column_or_already_linked_is_left_alone(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->storeDefinition(function (array $definition): array {
            unset($definition['blocks']['bibliografia']['catalog']['map']['call_number'], $definition['blocks']['bibliografia']['value']['libros']['items']['properties']['codigo']);

            return $definition;
        });
        $stored = $this->definition();

        $this->migration()->up();

        $this->assertSame($stored, $this->definition());
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function storeDefinition(callable $change): void
    {
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($change($this->definition()), JSON_UNESCAPED_UNICODE)]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_31_000253_bibliography_code_is_the_call_number.php');
    }
}
