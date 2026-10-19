<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Application\IncrementalPdfSignatureVerifier;
use App\Modules\Documents\Application\SignerHolderMatcher;
use Illuminate\Validation\ValidationException;
use Tests\Support\SignsPdfIncrementally;
use Tests\TestCase;

class SignatureTrustTest extends TestCase
{
    use SignsPdfIncrementally;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private string $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundle = tempnam(sys_get_temp_dir(), 'silabos-ca-');
    }

    protected function tearDown(): void
    {
        @unlink($this->bundle);
        parent::tearDown();
    }

    public function test_sin_entidades_configuradas_acepta_cualquier_certificado(): void
    {
        config(['signatures.trusted_ca_bundle' => null]);
        $signed = $this->signPdfIncrementally(self::PDF, $this->makeSigningCertificate('PERSONA DE PRUEBA'));

        $certificate = app(IncrementalPdfSignatureVerifier::class)->verify(self::PDF, $signed);

        $this->assertSame('PERSONA DE PRUEBA', $certificate->holder);
    }

    public function test_acepta_certificados_emitidos_por_una_entidad_de_confianza(): void
    {
        $authority = $this->makeCertificateAuthority();
        file_put_contents($this->bundle, $authority['pem']);
        config(['signatures.trusted_ca_bundle' => $this->bundle]);
        $signed = $this->signPdfIncrementally(self::PDF, $this->makeSigningCertificateIssuedBy($authority, 'JUAN CARLOS PEREZ GOMEZ'));

        $certificate = app(IncrementalPdfSignatureVerifier::class)->verify(self::PDF, $signed);

        $this->assertSame('JUAN CARLOS PEREZ GOMEZ', $certificate->holder);
    }

    public function test_rechaza_certificados_autofirmados_o_de_otra_entidad(): void
    {
        $authority = $this->makeCertificateAuthority();
        file_put_contents($this->bundle, $authority['pem']);
        config(['signatures.trusted_ca_bundle' => $this->bundle]);
        $verifier = app(IncrementalPdfSignatureVerifier::class);
        $stranger = $this->makeCertificateAuthority('OTRA ENTIDAD');

        foreach ([$this->makeSigningCertificate('AUTOFIRMADO'), $this->makeSigningCertificateIssuedBy($stranger, 'EMITIDO POR OTRA')] as $credentials) {
            try {
                $verifier->verify(self::PDF, $this->signPdfIncrementally(self::PDF, $credentials));
                $this->fail('Debió rechazar el certificado.');
            } catch (ValidationException $exception) {
                $this->assertSame(['El certificado de firma no fue emitido por una entidad certificadora reconocida.'], $exception->errors()['signed_pdf']);
            }
        }
    }

    public function test_la_coincidencia_del_titular_ignora_orden_tildes_y_mayusculas(): void
    {
        $matcher = new SignerHolderMatcher;

        $this->assertTrue($matcher->matches('JUAN CARLOS PEREZ GOMEZ', 'Pérez Gómez Juan Carlos'));
        $this->assertTrue($matcher->matches('JUAN PEREZ GOMEZ', 'Juan Carlos Pérez Gómez'));
        $this->assertFalse($matcher->matches('MARIA LUISA GOMEZ TORRES', 'Juan Carlos Pérez Gómez'));
        $this->assertFalse($matcher->matches('JUAN', 'Juan Carlos Pérez Gómez'));
        $this->assertFalse($matcher->matches('', 'Juan Carlos Pérez Gómez'));
    }

    public function test_el_comando_arma_el_archivo_solo_con_entidades_certificadoras_vigentes(): void
    {
        $authority = $this->makeCertificateAuthority();
        $leaf = $this->makeSigningCertificateIssuedBy($authority, 'PERSONA');
        $directory = sys_get_temp_dir().'/silabos-ca-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents("{$directory}/raiz.pem", $authority['pem']);
        openssl_x509_export($leaf['certificate'], $leafPem);
        file_put_contents("{$directory}/persona.pem", $leafPem);
        $output = "{$directory}/salida.pem";

        $this->artisan('signatures:build-trust-bundle', ['files' => ["{$directory}/persona.pem"], '--output' => $output])->assertFailed();
        $this->assertFileDoesNotExist($output);

        $this->artisan('signatures:build-trust-bundle', ['files' => ["{$directory}/raiz.pem"], '--output' => $output])->assertSuccessful();
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) file_get_contents($output));

        array_map('unlink', glob("{$directory}/*") ?: []);
        rmdir($directory);
    }

    public function test_el_archivo_de_entidades_incluido_solo_tiene_entidades_certificadoras(): void
    {
        $contents = (string) file_get_contents(base_path('resources/trust/ca-ecuador.pem'));
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $matches);

        $this->assertGreaterThanOrEqual(30, count($matches[0]));
        foreach ($matches[0] as $pem) {
            $parsed = openssl_x509_parse($pem);
            $this->assertIsArray($parsed);
            $this->assertGreaterThan(time(), (int) $parsed['validTo_time_t'], 'Certificado vencido: '.($parsed['name'] ?? ''));
        }
    }
}
