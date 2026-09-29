<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use App\Modules\Documents\Application\SyllabusDocumentContent;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Data\DocumentBundle;
use App\Modules\Documents\Domain\Data\DocumentRenderInput;
use App\Modules\Documents\Domain\Data\RenderedDocument;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use ZipArchive;

class PhpWordDocumentRenderer implements DocumentRenderer
{
    public const VERSION = 'phpword-docx-v4+text-pdf-v2';

    public const VERSION_GOTENBERG = 'phpword-docx-v4+libreoffice-pdf-v1';

    private const ZIP_MTIME = 315532800;

    public function __construct(
        private readonly SyllabusWordDocument $document,
        private readonly SyllabusDocumentContent $content,
        private readonly PlainTextPdf $pdf,
        private readonly GotenbergPdfConverter $converter,
    ) {}

    public function version(): string
    {
        return $this->converter->enabled() ? self::VERSION_GOTENBERG : self::VERSION;
    }

    public function render(DocumentRenderInput $input): DocumentBundle
    {
        $docx = $this->docx($input);

        return new DocumentBundle(
            docx: new RenderedDocument(
                format: 'docx',
                mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                extension: 'docx',
                bytes: $docx,
            ),
            pdf: new RenderedDocument(
                format: 'pdf',
                mime: 'application/pdf',
                extension: 'pdf',
                bytes: $this->converter->enabled()
                    ? $this->converter->convert($docx, $input->generatedAt)
                    : $this->pdf->write($this->content->lines($input)),
                reproducible: ! $this->converter->enabled(),
            ),
        );
    }

    private function docx(DocumentRenderInput $input): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'silabos-docx-');
        if ($temporary === false) {
            throw new RuntimeException('No se pudo preparar el documento DOCX.');
        }

        try {
            IOFactory::createWriter($this->document->build($input), 'Word2007')->save($temporary);

            $zip = new ZipArchive;
            if ($zip->open($temporary) !== true) {
                throw new RuntimeException('No se pudo releer el documento DOCX.');
            }
            $numbering = $zip->getFromName('word/numbering.xml');
            if (is_string($numbering)) {
                $counter = 0;
                $stable = preg_replace_callback(
                    '/<w:nsid w:val="[0-9A-F]{8}"\/>/',
                    function () use (&$counter): string {
                        return sprintf('<w:nsid w:val="%08X"/>', 0x51_1A_B0_00 + $counter++);
                    },
                    $numbering,
                );
                if (is_string($stable)) {
                    $zip->addFromString('word/numbering.xml', $stable);
                }
            }
            $document = $zip->getFromName('word/document.xml');
            if (is_string($document)) {
                $zip->addFromString('word/document.xml', WordTableGrid::fix($document));
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $zip->setMtimeIndex($index, self::ZIP_MTIME);
            }
            $zip->close();

            $bytes = file_get_contents($temporary);
            if ($bytes === false) {
                throw new RuntimeException('No se pudo leer el documento DOCX generado.');
            }

            return $bytes;
        } finally {
            @unlink($temporary);
        }
    }
}
