<?php

namespace Tests\Unit\Syllabus;

use App\Modules\Syllabus\Application\BibliographySections;
use Tests\TestCase;

class BibliographyAnswersTest extends TestCase
{
    public function test_only_what_the_career_and_teacher_fill_counts_as_the_bibliography(): void
    {
        $snapshot = fn (string $book, string $librarian): array => [
            'sections' => [
                ['key' => 'bibliografia', 'fields' => [
                    ['key' => 'bibliografia', 'quien_llena' => 'carrera', 'value' => ['rows' => [[$book]]]],
                    ['key' => 'verificacion_bibliografia', 'quien_llena' => 'institucional', 'value' => ['text' => $librarian]],
                ]],
                ['key' => 'revision', 'fields' => [['key' => 'firmas', 'quien_llena' => 'institucional', 'value' => null]]],
            ],
        ];

        $this->assertSame(BibliographySections::answers($snapshot('Libro', '')), BibliographySections::answers($snapshot('Libro', 'Bibliotecaria')));
        $this->assertNotSame(BibliographySections::answers($snapshot('Libro', '')), BibliographySections::answers($snapshot('Otro libro', '')));
        $this->assertSame([['section' => 'bibliografia', 'field' => 'bibliografia', 'value' => ['rows' => [['Libro']]]]], BibliographySections::answers($snapshot('Libro', '')));
    }
}
