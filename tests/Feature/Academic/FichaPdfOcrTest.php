<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Application\FichaDocumentText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class FichaPdfOcrTest extends TestCase
{
    public function test_even_a_pdf_with_legible_text_is_sent_to_ocr_without_local_extraction(): void
    {
        config(['openparser.enabled' => true, 'openparser.api_key' => 'clave-de-prueba', 'openparser.base_url' => 'https://api.openparser.dev']);
        $first = 'Álgebra Lineal. '.str_repeat('Descripción de matrices y vectores. ', 6);
        $second = 'Objetivo general: '.str_repeat('Resolver sistemas y aplicar operaciones. ', 6);
        Process::fake(['*' => Process::result(output: $first."\f".$second."\f")]);
        Http::fake([
            'api.openparser.dev/parse/async' => Http::response(['id' => 'digital']),
            'api.openparser.dev/jobs/digital' => Http::response(['status' => 'succeeded']),
            'api.openparser.dev/jobs/digital/result*' => Http::response(['markdown' => 'Contenido recuperado por el OCR.']),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'ficha-').'.pdf';
        file_put_contents($path, '%PDF-1.4');

        try {
            $result = app(FichaDocumentText::class)->read($path, 'proyecto.pdf');

            $this->assertSame('ocr', $result['method']);
            $this->assertNull($result['blocks'][0]['page']);
            $this->assertSame('proyecto.pdf', $result['blocks'][0]['document']);
            $this->assertSame('Contenido recuperado por el OCR.', $result['blocks'][0]['text']);
            Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/parse/async'));
            Process::assertNothingRan();
        } finally {
            @unlink($path);
        }
    }

    public function test_a_pdf_is_read_with_the_openparser_ocr_when_it_is_configured(): void
    {
        Process::fake();
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
        Process::assertNothingRan();
    }

    public function test_a_failed_ocr_job_explains_which_document_could_not_be_read(): void
    {
        Process::fake(['*' => Process::result(output: str_repeat('Texto legible que no debe usarse como alternativa. ', 8))]);
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
            Process::assertNothingRan();
        }
    }

    public function test_an_unconfigured_ocr_is_reported_without_reading_the_pdf_locally(): void
    {
        config(['openparser.enabled' => false]);
        Process::fake();
        Http::fake();
        $this->expectExceptionMessage('El servicio OCR no está configurado.');

        try {
            app(FichaDocumentText::class)->read('/tmp/documento-sintetico.pdf', 'proyecto.pdf');
        } finally {
            Process::assertNothingRan();
            Http::assertNothingSent();
        }
    }
}
