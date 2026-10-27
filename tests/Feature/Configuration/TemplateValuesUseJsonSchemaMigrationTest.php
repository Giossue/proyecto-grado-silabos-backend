<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemplateValuesUseJsonSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_template_moves_to_json_schema_names_and_marks_long_texts(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $definition = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true);
        $definition['blocks']['identificacion']['value'] = ['disability' => ['type' => 'object', 'fields' => [
            'answer' => ['type' => 'choice', 'label' => 'Estudiantes con discapacidad', 'options' => ['si' => 'Sí', 'no' => 'No']],
            'type' => ['type' => 'text', 'max' => 500, 'multiline' => false],
            'description' => ['type' => 'text', 'max' => 500, 'multiline' => false],
        ]]];
        DB::table('plantilla_silabo')->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);

        $this->migration()->up();

        $stored = json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true)['blocks'];
        $disability = $stored['identificacion']['value']['disability'];
        $this->assertSame([['const' => 'si', 'title' => 'Sí'], ['const' => 'no', 'title' => 'No']], $disability['properties']['answer']['oneOf']);
        $this->assertSame('Estudiantes con discapacidad', $disability['properties']['answer']['title']);
        $this->assertFalse($disability['properties']['type']['multiline']);
        $this->assertTrue($disability['properties']['description']['multiline']);
        $this->assertTrue($stored['unidades']['value']['units']['items']['properties']['semanas']['items']['properties']['contenidos']['multiline']);
        $this->assertFalse($stored['unidades']['value']['units']['items']['properties']['nombre']['multiline']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_27_000243_template_values_use_json_schema.php');
    }
}
