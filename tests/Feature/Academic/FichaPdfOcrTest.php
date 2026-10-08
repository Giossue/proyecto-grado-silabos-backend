<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Application\FichaDocumentText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FichaPdfOcrTest extends TestCase
{
    public function test_a_pdf_is_read_with_the_openparser_ocr_when_it_is_configured(): void
    {
        config(['openparser.enabled' => true, 'openparser.api_key' => 'clave-de-prueba', 'openparser.base_url' => 'https://api.openparser.dev']);
        Http::fake([
            'api.openparser.dev/parse/async' => Http::response(['id' => 'job-1', 'operation' => 'parse', 'output_format' => 'openparser@1']),
            'api.openparser.dev/jobs/job-1/result*' => Http::response(['output_format' => 'openparser@1', 'markdown' => "# Algoritmos\nObjetivo general: resolver problemas con algoritmos."]),
            'api.openparser.dev/jobs/job-1' => Http::response(['status' => 'succeeded']),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'ficha-').'.pdf';
        file_put_contents($path, '%PDF-1.4 escaneado');

        $blocks = app(FichaDocumentText::class)->blocks($path, 'proyecto.pdf');
        @unlink($path);

        $this->assertStringContainsString('Objetivo general: resolver problemas con algoritmos.', implode("\n", $blocks));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/parse/async') && $request->hasHeader('Authorization', 'Bearer clave-de-prueba'));
    }

    public function test_a_failed_ocr_job_explains_which_document_could_not_be_read(): void
    {
        config(['openparser.enabled' => true, 'openparser.api_key' => 'clave-de-prueba', 'openparser.base_url' => 'https://api.openparser.dev']);
        Http::fake([
            'api.openparser.dev/parse/async' => Http::response(['id' => 'job-2']),
            'api.openparser.dev/jobs/job-2' => Http::response(['status' => 'failed']),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'ficha-').'.pdf';
        file_put_contents($path, '%PDF-1.4');

        $this->expectExceptionMessage('El OCR no pudo leer «malla.pdf».');
        try {
            app(FichaDocumentText::class)->blocks($path, 'malla.pdf');
        } finally {
            @unlink($path);
        }
    }
}
