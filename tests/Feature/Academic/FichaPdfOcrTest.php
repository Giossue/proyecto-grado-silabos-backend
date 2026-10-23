<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Application\FichaDocumentText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class FichaPdfOcrTest extends TestCase
{
    public function test_a_pdf_with_text_is_read_locally_with_pages_without_sending_it_to_ocr(): void
    {
        config(['openparser.enabled' => true, 'openparser.api_key' => 'clave-de-prueba', 'openparser.base_url' => 'https://api.openparser.dev']);
        Http::fake();
        $first = 'Álgebra Lineal. '.str_repeat('Descripción de matrices y vectores. ', 6);
        $second = 'Objetivo general: '.str_repeat('Resolver sistemas y aplicar operaciones. ', 6);
        Process::fake(['*' => Process::result(output: $first."\f".$second."\f")]);

        $result = app(FichaDocumentText::class)->read('/tmp/documento-sintetico.pdf', 'proyecto.pdf');

        $this->assertSame('pdf_text', $result['method']);
        $this->assertSame([1, 2], array_column($result['blocks'], 'page'));
        $this->assertSame('proyecto.pdf', $result['blocks'][0]['document']);
        $this->assertStringContainsString('Resolver sistemas', $result['blocks'][1]['text']);
        Http::assertNothingSent();
    }

    public function test_a_pdf_is_read_with_the_openparser_ocr_when_it_is_configured(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
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
        Process::fake(['*' => Process::result(exitCode: 1)]);
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

    public function test_a_mixed_pdf_uses_ocr_when_a_scanned_page_has_no_native_text(): void
    {
        config(['openparser.enabled' => true, 'openparser.api_key' => 'clave-de-prueba', 'openparser.base_url' => 'https://api.openparser.dev']);
        Process::fake(['*' => Process::result(output: str_repeat('Página con texto académico. ', 8)."\f\f")]);
        Http::fake([
            'api.openparser.dev/parse/async' => Http::response(['id' => 'mixed']),
            'api.openparser.dev/jobs/mixed' => Http::response(['status' => 'succeeded']),
            'api.openparser.dev/jobs/mixed/result*' => Http::response(['markdown' => 'Texto de ambas páginas recuperado.']),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'ficha-').'.pdf';
        file_put_contents($path, '%PDF-1.4');

        try {
            $result = app(FichaDocumentText::class)->read($path, 'mixto.pdf');
            $this->assertSame('ocr', $result['method']);
            $this->assertStringContainsString('ambas páginas', $result['blocks'][0]['text']);
            $this->assertNull($result['blocks'][0]['page']);
        } finally {
            @unlink($path);
        }
    }
}
