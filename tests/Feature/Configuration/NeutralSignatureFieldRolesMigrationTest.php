<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NeutralSignatureFieldRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_signature_field_drops_the_name_label_and_uses_neutral_roles_without_counting_as_an_edit(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $id = (string) DB::table('definiciones_campo')->value('definicion_campo_id');
        DB::table('definiciones_campo')->where('definicion_campo_id', $id)->update(['contenido_definicion_campo' => json_encode($this->oldSignatures(), JSON_UNESCAPED_UNICODE)]);
        $template = DB::table('plantilla_silabo')->first(['definicion_plantilla', 'plantilla_modificada_en']);

        $this->migration()->up();

        $new = $this->stored($id);
        $this->assertSame(0, substr_count($new, '"text":"Nombre:"'));
        foreach (['Docente – Investigador/a', 'Coordinación de Carrera', 'Biblioteca'] as $role) {
            $this->assertStringContainsString('"text":"'.$role.'"', $new);
        }
        foreach (['Profesor – Investigador', 'Coordinador de Carrera', '"text":"Bibliotecario"'] as $role) {
            $this->assertStringNotContainsString($role, $new);
        }
        foreach (['nombre_docente', 'nombre_coordinador', 'nombre_bibliotecario', '"text":"Firma:"', '"text":"Bibliotecario de turno"'] as $kept) {
            $this->assertStringContainsString($kept, $new);
        }
        $this->assertEquals($template, DB::table('plantilla_silabo')->first(['definicion_plantilla', 'plantilla_modificada_en']));

        $this->migration()->up();
        $this->assertSame($new, $this->stored($id));
    }

    /** @return array<string, mixed> */
    private function oldSignatures(): array
    {
        $paragraph = fn (array $inline): array => ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [$inline]];
        $text = fn (string $value): array => $paragraph(['type' => 'text', 'text' => $value]);
        $variable = fn (string $id): array => $paragraph(['type' => 'variable', 'attrs' => ['id' => $id, 'label' => $id]]);
        $cell = fn (array $content): array => ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => $content];

        return ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [$cell([$text('Firma:')]), $cell([$text('Firma:')]), $cell([$text('Firma:')])]],
            ['type' => 'tableRow', 'content' => [
                $cell([$text('Nombre:'), $variable('nombre_docente'), $text('Profesor – Investigador')]),
                $cell([$text('Nombre:'), $variable('nombre_coordinador'), $text('Coordinador de Carrera')]),
                $cell([$text('Nombre:'), $variable('nombre_bibliotecario'), $text('Bibliotecario')]),
            ]],
            // Otra celda con el mismo nombre pero sin el cargo: no es la de firma y no se toca.
            ['type' => 'tableRow', 'content' => [$cell([$variable('nombre_bibliotecario'), $text('Bibliotecario de turno')])]],
        ]]]];
    }

    private function stored(string $id): string
    {
        return json_encode(json_decode((string) DB::table('definiciones_campo')->where('definicion_campo_id', $id)->value('contenido_definicion_campo'), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_03_000263_signature_field_has_neutral_roles.php');
    }
}
