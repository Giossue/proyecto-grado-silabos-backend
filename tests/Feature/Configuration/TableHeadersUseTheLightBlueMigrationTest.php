<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TableHeadersUseTheLightBlueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_strong_blue_default_becomes_the_light_blue_without_marking_the_template_as_changed(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->setColors(['table_header_background' => '#4f81bd', 'table_header_text' => '#FFFFFF']);
        $changedAt = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->up();

        $this->assertSame(['table_header_background' => '#C3D4EA', 'table_header_text' => '#000000'], $this->colors());
        $this->assertSame($changedAt, DB::table('plantilla_silabo')->value('plantilla_modificada_en'));
    }

    public function test_colors_chosen_by_administration_are_kept(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->setColors(['table_header_background' => '#1F4E78', 'table_header_text' => '#FFFFFF']);

        $this->migration()->up();

        $this->assertSame(['table_header_background' => '#1F4E78', 'table_header_text' => '#FFFFFF'], $this->colors());
    }

    /**
     * @param  array<string, string>  $colors
     */
    private function setColors(array $colors): void
    {
        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $definition['document']['colors'] = array_replace($definition['document']['colors'], $colors);
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function colors(): array
    {
        $colors = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true)['document']['colors'];

        return array_intersect_key($colors, array_flip(['table_header_background', 'table_header_text']));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_01_000254_table_headers_use_the_light_blue.php');
    }
}
