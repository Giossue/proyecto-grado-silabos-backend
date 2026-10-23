<?php

namespace Tests\Unit\Academic;

use App\Modules\Academic\Application\FichaEvidence;
use PHPUnit\Framework\TestCase;

class FichaEvidenceTest extends TestCase
{
    public function test_it_recovers_continuations_and_stops_at_the_next_subject_and_document(): void
    {
        $blocks = [
            ['document' => 'carrera.pdf', 'page' => 1, 'text' => "Índice\nÁlgebra Lineal ........ 10\nProgramación Orientada a Objetos ........ 20"],
            ['document' => 'carrera.pdf', 'page' => 10, 'text' => "ÁLGEBRA LINEAL\nDescripción: estudio de matrices."],
            ['document' => 'carrera.pdf', 'page' => 11, 'text' => 'Objetivo general: resolver sistemas.'],
            ['document' => 'carrera.pdf', 'page' => 12, 'text' => 'Objetivos específicos: representar vectores.'],
            ['document' => 'carrera.pdf', 'page' => 13, 'text' => 'Resultados de aprendizaje: aplica transformaciones.'],
            ['document' => 'carrera.pdf', 'page' => 14, 'text' => "Programación Orientada a Objetos\nObjetivo: diseñar clases."],
            ['document' => 'otro.pdf', 'page' => 1, 'text' => 'Objetivo general de otra carrera.'],
        ];
        $catalog = [['name' => 'Álgebra Lineal', 'code' => 'MAT-01'], ['name' => 'Programación Orientada a Objetos', 'code' => 'POO-02']];

        $text = implode("\n", (new FichaEvidence)->for('Álgebra Lineal', 'MAT-01', $blocks, $catalog));

        $this->assertStringContainsString('resolver sistemas', $text);
        $this->assertStringContainsString('aplica transformaciones', $text);
        $this->assertStringContainsString('Página: 13', $text);
        $this->assertStringNotContainsString('diseñar clases', $text);
        $this->assertStringNotContainsString('otra carrera', $text);
        $this->assertStringNotContainsString('........', $text);
    }

    public function test_field_search_prioritizes_late_evidence_and_accepts_name_variants(): void
    {
        $blocks = ["PROGRAMACION ORIENTADA OBJETOS\nDescripción: asignatura de programación."];
        for ($index = 0; $index < 20; $index++) {
            $blocks[] = str_repeat('Contenido extenso de la asignatura. ', 60);
        }
        $blocks[] = 'Objetivo general: diseñar sistemas orientados a objetos.';
        $evidence = new FichaEvidence;

        $initial = implode("\n", $evidence->for('Programación Orientada a Objetos', 'POO-02', $blocks));
        $focused = implode("\n", $evidence->for('Programación Orientada a Objetos', 'POO-02', $blocks, [], 'Objetivo general'));

        $this->assertStringNotContainsString('diseñar sistemas', $initial);
        $this->assertStringContainsString('diseñar sistemas', $focused);
        $this->assertLessThan(25000, mb_strlen($focused));
    }

    public function test_it_does_not_match_partial_codes_or_unrelated_names(): void
    {
        $this->assertSame([], (new FichaEvidence)->for('Álgebra Lineal', 'MAT-01', ['MAT-010 Geometría. Objetivo: analizar figuras.']));
    }

    public function test_a_subject_missing_from_the_catalog_still_ends_the_previous_section(): void
    {
        $blocks = ["Asignatura: Álgebra Lineal\nObjetivo: resolver sistemas.", "Asignatura: Geometría\nObjetivo: analizar figuras."];

        $text = implode("\n", (new FichaEvidence)->for('Álgebra Lineal', 'MAT-01', $blocks));

        $this->assertStringContainsString('resolver sistemas', $text);
        $this->assertStringNotContainsString('analizar figuras', $text);
    }

    public function test_documents_with_the_same_filename_do_not_share_subject_context(): void
    {
        $blocks = [
            ['text' => 'Álgebra Lineal y matrices.', 'document' => 'proyecto.pdf', 'source' => 0, 'page' => 1],
            ['text' => 'Objetivo general de otra carrera.', 'document' => 'proyecto.pdf', 'source' => 1, 'page' => 1],
        ];

        $text = implode("\n", (new FichaEvidence)->for('Álgebra Lineal', 'MAT-01', $blocks));

        $this->assertStringNotContainsString('otra carrera', $text);
    }
}
