<?php

namespace Tests\Unit\Configuration;

use App\Modules\Configuration\Application\OpenParserDocumentConverter;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrPage;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use PHPUnit\Framework\TestCase;

final class OpenParserDocumentConverterTest extends TestCase
{
    public function test_it_inserts_a_markdown_divider_between_pdf_pages(): void
    {
        $document = (new OpenParserDocumentConverter)->convert(
            new AcademicSourceOcrResult(
                model: 'paddleocr-vl-1.6',
                pages: [
                    new AcademicSourceOcrPage(index: 0, markdown: 'Contenido de la primera página.'),
                    new AcademicSourceOcrPage(index: 1, markdown: 'Contenido de la segunda página.'),
                ],
                usage: [],
                requestId: 'job-1',
            ),
            static fn (AcademicSourceOcrPage $page, string $imageId, string $resource): ?string => null,
        );

        self::assertSame(
            ['paragraph', 'horizontalRule', 'paragraph'],
            array_column($document['content'], 'type'),
        );
        self::assertSame(1, $document['content'][1]['attrs']['sourcePage']);
    }
}
