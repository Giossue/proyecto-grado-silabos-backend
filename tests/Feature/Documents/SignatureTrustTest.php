<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Application\CmsSignatureVerifier;
use App\Modules\Documents\Application\SignerHolderMatcher;
use Illuminate\Validation\ValidationException;
use Tests\Support\SignsStatements;
use Tests\TestCase;

class SignatureTrustTest extends TestCase
{
    use SignsStatements;

    private const STATEMENT = 'silabos-ueb|silabo:00000000-0000-0000-0000-000000000000|accion:envio_docente|huella:'.self::HASH;

    private const HASH = '0000000000000000000000000000000000000000000000000000000000000000';

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
        $signed = $this->signStatement(self::STATEMENT, $this->makeSigningCertificate('PERSONA DE PRUEBA'));

        $certificate = app(CmsSignatureVerifier::class)->verify(self::STATEMENT, $signed);

        $this->assertSame('PERSONA DE PRUEBA', $certificate->holder);
    }

    public function test_acepta_certificados_emitidos_por_una_entidad_de_confianza(): void
    {
        $authority = $this->makeCertificateAuthority();
        file_put_contents($this->bundle, $authority['pem']);
        config(['signatures.trusted_ca_bundle' => $this->bundle]);
        $signed = $this->signStatement(self::STATEMENT, $this->makeSigningCertificateIssuedBy($authority, 'JUAN CARLOS PEREZ GOMEZ'));

        $certificate = app(CmsSignatureVerifier::class)->verify(self::STATEMENT, $signed);

        $this->assertSame('JUAN CARLOS PEREZ GOMEZ', $certificate->holder);
    }

    public function test_rechaza_certificados_autofirmados_o_de_otra_entidad(): void
    {
        $authority = $this->makeCertificateAuthority();
        file_put_contents($this->bundle, $authority['pem']);
        config(['signatures.trusted_ca_bundle' => $this->bundle]);
        $verifier = app(CmsSignatureVerifier::class);
        $stranger = $this->makeCertificateAuthority('OTRA ENTIDAD');

        foreach ([$this->makeSigningCertificate('AUTOFIRMADO'), $this->makeSigningCertificateIssuedBy($stranger, 'EMITIDO POR OTRA')] as $credentials) {
            try {
                $verifier->verify(self::STATEMENT, $this->signStatement(self::STATEMENT, $credentials));
                $this->fail('Debió rechazar el certificado.');
            } catch (ValidationException $exception) {
                $this->assertSame(['El certificado no es de una entidad reconocida.'], $exception->errors()['signature']);
            }
        }
    }

    public function test_acepta_certificados_firmados_directamente_por_una_entidad_de_la_lista_aunque_falte_su_raiz(): void
    {
        $root = $this->makeCertificateAuthority('RAIZ AUSENTE');
        $intermediate = $this->makeCertificateAuthority('ENTIDAD INTERMEDIA', $root);
        file_put_contents($this->bundle, $intermediate['pem']);
        config(['signatures.trusted_ca_bundle' => $this->bundle]);
        $verifier = app(CmsSignatureVerifier::class);

        $signed = $this->signStatement(self::STATEMENT, $this->makeSigningCertificateIssuedBy($intermediate, 'JUAN CARLOS PEREZ GOMEZ'));
        $this->assertSame('JUAN CARLOS PEREZ GOMEZ', $verifier->verify(self::STATEMENT, $signed)->holder);

        $this->expectException(ValidationException::class);
        $verifier->verify(self::STATEMENT, $this->signStatement(self::STATEMENT, $this->makeSigningCertificateIssuedBy($this->makeCertificateAuthority('OTRA'), 'INTRUSO')));
    }

    public function test_la_coincidencia_del_titular_ignora_orden_tildes_y_mayusculas(): void
    {
        $matcher = new SignerHolderMatcher;

        $this->assertTrue($matcher->matches('JUAN CARLOS PEREZ GOMEZ', 'Pérez Gómez Juan Carlos'));
        $this->assertTrue($matcher->matches('JOSÉ  ÑÚÑEZ   PEÑA', 'jose nuñez peña'));
        $this->assertFalse($matcher->matches('JUAN PEREZ GOMEZ', 'Juan Carlos Pérez Gómez'));
        $this->assertFalse($matcher->matches('JUAN CARLOS PEREZ GOMEZ', 'Juan Pérez'));
        $this->assertFalse($matcher->matches('JUAN CARLOS PEREZ GOMEZ', 'Juan Carlos Pérez'));
        $this->assertFalse($matcher->matches('JUANA CARLOTA PEREZ GOMEZ', 'Juan Carlos Pérez Gómez'));
        $this->assertFalse($matcher->matches('MARIA LUISA GOMEZ TORRES', 'Juan Carlos Pérez Gómez'));
        $this->assertFalse($matcher->matches('JUAN', 'Juan Carlos Pérez Gómez'));
        $this->assertFalse($matcher->matches('', 'Juan Carlos Pérez Gómez'));
    }

    public function test_la_entidad_listada_no_valida_certificados_que_no_son_de_firma(): void
    {
        $root = $this->makeCertificateAuthority('RAIZ AUSENTE');
        $intermediate = $this->makeCertificateAuthority('ENTIDAD INTERMEDIA', $root);
        file_put_contents($this->bundle, $intermediate['pem']);
        config(['signatures.trusted_ca_bundle' => $this->bundle]);
        $verifier = app(CmsSignatureVerifier::class);

        foreach ([
            "keyUsage=keyEncipherment\n",
            "keyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n",
            "keyUsage=digitalSignature,nonRepudiation\nextendedKeyUsage=anyExtendedKeyUsage\n",
        ] as $extensions) {
            $signed = $this->signStatement(self::STATEMENT, $this->makeLeafIssuedBy($intermediate, 'JUAN CARLOS PEREZ GOMEZ', $extensions));
            try {
                $verifier->verify(self::STATEMENT, $signed);
                $this->fail("Debió rechazar el certificado con {$extensions}.");
            } catch (ValidationException $exception) {
                $this->assertSame(['El certificado no es de una entidad reconocida.'], $exception->errors()['signature']);
            }
        }
    }

    public function test_en_produccion_sin_entidades_configuradas_rechaza_la_firma(): void
    {
        $signed = $this->signStatement(self::STATEMENT, $this->makeSigningCertificate('PERSONA DE PRUEBA'));
        $verifier = app(CmsSignatureVerifier::class);
        $this->app['env'] = 'production';

        try {
            foreach ([null, '', sys_get_temp_dir().'/silabos-no-existe-'.bin2hex(random_bytes(4)).'.pem'] as $bundle) {
                config(['signatures.trusted_ca_bundle' => $bundle]);
                try {
                    $verifier->verify(self::STATEMENT, $signed);
                    $this->fail('Debió rechazar la firma en producción.');
                } catch (ValidationException $exception) {
                    $this->assertSame(
                        ['No se puede verificar la firma: falta configurar las entidades certificadoras de confianza. Avise a Administración.'],
                        $exception->errors()['signature'],
                    );
                }
            }
        } finally {
            $this->app['env'] = 'testing';
        }

        config(['signatures.trusted_ca_bundle' => null]);
        $this->assertSame('PERSONA DE PRUEBA', $verifier->verify(self::STATEMENT, $signed)->holder);
    }

    /**
     * @param  array{certificate: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey, pem: string}  $authority
     * @return array{certificate: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey}
     */
    private function makeLeafIssuedBy(array $authority, string $holder, string $extensions): array
    {
        $config = $this->opensslConfig("[req]\ndistinguished_name=dn\n[dn]\n[v3_leaf]\nbasicConstraints=CA:FALSE\n{$extensions}");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $request = openssl_csr_new(['countryName' => 'EC', 'commonName' => $holder], $key, ['digest_alg' => 'sha256', 'config' => $config]);
        $this->assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $request);
        $certificate = openssl_csr_sign($request, $authority['certificate'], $authority['key'], 30, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_leaf'], random_int(1000, 999999));
        $this->assertInstanceOf(\OpenSSLCertificate::class, $certificate);

        return ['certificate' => $certificate, 'key' => $key];
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
