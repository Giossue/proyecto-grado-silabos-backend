<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NeutralSignatureRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_saved_template_drops_the_name_label_and_uses_neutral_roles_without_counting_as_an_edit(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->migration()->down();
        $old = $this->stored();
        $this->assertSame(3, substr_count($old, '"text":"Nombre:"'));
        $this->assertStringContainsString('"text":"Profesor – Investigador"', $old);
        $modifiedAt = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->up();

        $new = $this->stored();
        $this->assertSame(0, substr_count($new, '"text":"Nombre:"'));
        foreach (['Docente – Investigador/a', 'Coordinación de Carrera', 'Biblioteca'] as $role) {
            $this->assertStringContainsString('"text":"'.$role.'"', $new);
        }
        foreach (['Profesor – Investigador', 'Coordinador de Carrera', '"text":"Bibliotecario"'] as $role) {
            $this->assertStringNotContainsString($role, $new);
        }
        $this->assertEquals($modifiedAt, DB::table('plantilla_silabo')->value('plantilla_modificada_en'));

        $this->migration()->up();
        $this->assertSame($new, $this->stored());
    }

    private function stored(): string
    {
        return json_encode(json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_02_000259_signature_table_has_neutral_roles.php');
    }
}
