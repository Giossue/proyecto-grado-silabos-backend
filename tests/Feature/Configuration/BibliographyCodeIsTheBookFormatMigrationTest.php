<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BibliographyCodeIsTheBookFormatMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_code_column_takes_the_book_format_instead_of_the_call_number(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->storeDefinition(function (array $definition): array {
            unset($definition['blocks']['bibliografia']['catalog']['map']['format']);
            $definition['blocks']['bibliografia']['catalog']['map']['call_number'] = 'codigo';

            return $definition;
        });
        $before = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->up();

        $map = $this->definition()['blocks']['bibliografia']['catalog']['map'];
        $this->assertSame('codigo', $map['format']);
        $this->assertArrayNotHasKey('call_number', $map);
        $this->assertEquals($before, DB::table('plantilla_silabo')->value('plantilla_modificada_en'));
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
        return require database_path('migrations/2026_11_02_000258_bibliography_code_is_the_book_format.php');
    }
}
