<?php

namespace Tests\Unit\Academic;

use App\Modules\Academic\Infrastructure\Gateways\OpenParserPdfReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenParserPdfReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['openparser.base_url' => 'https://api.openparser.dev', 'openparser.api_key' => 'clave', 'openparser.poll_delay_seconds' => 0]);
    }

    public function test_it_sends_an_idempotency_key_and_returns_the_text(): void
    {
        Http::fake([
            'api.openparser.dev/parse/async' => Http::response(['id' => 'opj_1', 'status' => 'queued'], 202),
            'api.openparser.dev/jobs/opj_1' => Http::response(['id' => 'opj_1', 'status' => 'succeeded']),
            'api.openparser.dev/jobs/opj_1/result*' => Http::response(['markdown' => 'Hola mundo']),
        ]);
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, '%PDF-1.4');

        $this->assertSame('Hola mundo', (new OpenParserPdfReader)->text($pdf, 'ficha.pdf'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/parse/async') && $request->hasHeader('Idempotency-Key'));
    }

    public function test_a_rejection_explains_the_http_status_and_reason(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'quota', 'message' => 'x']], 429)]);
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, '%PDF-1.4');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El OCR rechazó el PDF (HTTP 429: se agotó la cuota o el límite de uso del servicio OCR).');
        (new OpenParserPdfReader)->text($pdf, 'ficha.pdf');
    }
}
