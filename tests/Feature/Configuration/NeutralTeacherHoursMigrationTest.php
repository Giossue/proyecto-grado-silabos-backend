<?php

namespace Tests\Feature\Configuration;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NeutralTeacherHoursMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hours_header_says_docente_in_the_template_and_its_fields_without_counting_as_an_edit(): void
    {
        $this->seed([DatabaseSeeder::class, SyllabusTemplateSeeder::class]);
        $this->migration()->down();
        $fieldId = (string) DB::table('definiciones_campo')->value('definicion_campo_id');
        DB::table('definiciones_campo')->where('definicion_campo_id', $fieldId)->update(['contenido_definicion_campo' => json_encode($this->fieldWith('HORAS DEL PROFESOR'), JSON_UNESCAPED_UNICODE)]);
        $this->assertStringContainsString('"text":"HORAS DEL PROFESOR"', $this->template());
        $modifiedAt = DB::table('plantilla_silabo')->value('plantilla_modificada_en');

        $this->migration()->up();

        $template = $this->template();
        $this->assertStringContainsString('"text":"HORAS DEL DOCENTE"', $template);
        $this->assertStringNotContainsString('PROFESOR', $template);
        $this->assertStringContainsString('"text":"HORAS DEL ESTUDIANTE"', $template);
        $this->assertEquals($this->fieldWith('HORAS DEL DOCENTE'), json_decode($this->field($fieldId), true));
        $this->assertEquals($modifiedAt, DB::table('plantilla_silabo')->value('plantilla_modificada_en'));

        $this->migration()->up();
        $this->assertSame($template, $this->template());
    }

    /** @return array<string, mixed> */
    private function fieldWith(string $header): array
    {
        $paragraph = fn (string $text): array => ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => $text]]];

        // «HORAS DEL PROFESOR DE PLANTA» no es el encabezado: solo se cambia el texto exacto.
        return ['type' => 'doc', 'content' => [$paragraph($header), $paragraph('HORAS DEL PROFESOR DE PLANTA')]];
    }

    private function template(): string
    {
        return json_encode(json_decode((string) DB::table('plantilla_silabo')->value('definicion_plantilla'), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function field(string $id): string
    {
        return json_encode(json_decode((string) DB::table('definiciones_campo')->where('definicion_campo_id', $id)->value('contenido_definicion_campo'), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_03_000264_teacher_hours_header_is_neutral.php');
    }
}
