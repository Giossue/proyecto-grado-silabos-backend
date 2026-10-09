<?php

namespace Tests\Unit\Configuration;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldDocumentTest extends TestCase
{
    public function test_normalizes_text_lists_tables_and_catalog_variables(): void
    {
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['id' => 'nombre_asignatura']]]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Resultado']]]]]]],
            ...FreeText::fromRows([['key' => 'tema', 'label' => 'Tema']], [['tema' => 'Contenido']])['content'],
        ]];
        $normalized = FieldDocument::normalize($document);
        $resolved = FieldDocument::resolve($normalized, ['nombre_asignatura' => 'Calidad de software']);
        $this->assertStringContainsString('Calidad de software', FreeText::plainText($resolved));
        $this->assertStringContainsString('Resultado', FreeText::plainText($resolved));
    }

    public function test_rejects_unknown_variables_and_old_typed_field_markers(): void
    {
        foreach ([['type' => 'variable', 'attrs' => ['id' => 'secreto']], ['type' => 'field', 'attrs' => ['key' => 'dato', 'kind' => 'numero']]] as $node) {
            try {
                FieldDocument::normalize(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$node]]]]);
                $this->fail('El documento debe rechazarse.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content', $exception->errors());
            }
        }
    }

    public function test_accepts_free_rows_and_totals_without_academic_validation(): void
    {
        $document = FreeText::fromRows([['key' => 'horas', 'label' => 'Horas']], [['horas' => 'No calculado'], ['horas' => 'Total libre']]);
        $this->assertStringContainsString('Total libre', FreeText::plainText(FieldDocument::normalize($document)));
    }
}
