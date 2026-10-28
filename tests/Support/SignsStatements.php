<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Documents\Domain\SigningStatement;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

trait SignsStatements
{
    /** @var array<string, array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey}> */
    private static array $signingCredentials = [];

    /** @return array{statement: string, cms: string} */
    private function teacherSignature(Syllabus $syllabus, User $teacher): array
    {
        $fresh = $syllabus->fresh() ?? $syllabus;
        $fingerprint = app(CanonicalHasher::class)->hash(app(SyllabusSnapshot::class)->build($fresh));

        return $this->decisionSignature(SigningStatement::for($fresh->id, 'docente', $fingerprint), $teacher->nombre);
    }

    /** @return array{statement: string, cms: string} */
    private function revisionSignature(SyllabusRevision $revision, string $role, User $signer): array
    {
        return $this->decisionSignature(SigningStatement::for($revision->silabo_id, $role, $revision->huella_sha256), $signer->nombre);
    }

    /** @return array{statement: string, cms: string} */
    private function decisionSignature(string $statement, string $holder): array
    {
        self::$signingCredentials[$holder] ??= $this->makeSigningCertificate($holder, 3650);

        return ['statement' => $statement, 'cms' => $this->signStatement($statement, self::$signingCredentials[$holder])];
    }

    /** @return array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey} */
    private function makeSigningCertificate(string $holder, int $days = 30): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('No se pudo crear la llave de prueba.');
        }
        $request = openssl_csr_new(['countryName' => 'EC', 'commonName' => $holder, 'organizationName' => 'ENTIDAD DE PRUEBA'], $key, ['digest_alg' => 'sha256']);
        if ($request === false || $request === true) {
            throw new RuntimeException('No se pudo crear la solicitud de certificado de prueba.');
        }
        $certificate = openssl_csr_sign($request, null, $key, $days, ['digest_alg' => 'sha256'], random_int(1000, 999999));
        if (! $certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('No se pudo firmar el certificado de prueba.');
        }

        return ['certificate' => $certificate, 'key' => $key];
    }

    /**
     * @param  array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}|null  $parent
     * @return array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}
     */
    private function makeCertificateAuthority(string $name = 'ENTIDAD CERTIFICADORA DE PRUEBA', ?array $parent = null): array
    {
        $config = $this->opensslConfig('[req]'."\n".'distinguished_name=dn'."\n".'[dn]'."\n".'[v3_ca]'."\n".'basicConstraints=critical,CA:TRUE'."\n".'keyUsage=critical,keyCertSign,cRLSign'."\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $request = openssl_csr_new(['countryName' => 'EC', 'commonName' => $name], $key, ['digest_alg' => 'sha256', 'config' => $config]);
        $certificate = openssl_csr_sign($request, $parent['certificate'] ?? null, $parent['key'] ?? $key, 365, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_ca'], random_int(1000, 999999));
        if (! $certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('No se pudo crear la entidad certificadora de prueba.');
        }
        openssl_x509_export($certificate, $pem);

        return ['certificate' => $certificate, 'key' => $key, 'pem' => $pem];
    }

    /**
     * @param  array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}  $authority
     * @return array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey}
     */
    private function makeSigningCertificateIssuedBy(array $authority, string $holder): array
    {
        $config = $this->opensslConfig('[req]'."\n".'distinguished_name=dn'."\n".'[dn]'."\n".'[v3_leaf]'."\n".'basicConstraints=CA:FALSE'."\n".'keyUsage=digitalSignature,nonRepudiation'."\n".'extendedKeyUsage=emailProtection'."\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $request = openssl_csr_new(['countryName' => 'EC', 'commonName' => $holder], $key, ['digest_alg' => 'sha256', 'config' => $config]);
        $certificate = openssl_csr_sign($request, $authority['certificate'], $authority['key'], 30, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_leaf'], random_int(1000, 999999));
        if (! $certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('No se pudo emitir el certificado de prueba.');
        }

        return ['certificate' => $certificate, 'key' => $key];
    }

    private function opensslConfig(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'silabos-openssl-');
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey} $credentials */
    private function signStatement(string $statement, array $credentials): string
    {
        $directory = sys_get_temp_dir().'/silabos-prueba-firma-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents("{$directory}/declaracion.txt", $statement);
            $ok = openssl_cms_sign(
                "{$directory}/declaracion.txt",
                "{$directory}/firma.der",
                $credentials['certificate'],
                $credentials['key'],
                [],
                OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                OPENSSL_ENCODING_DER,
            );
            if (! $ok) {
                throw new RuntimeException('No se pudo firmar la declaración de prueba.');
            }

            return base64_encode((string) file_get_contents("{$directory}/firma.der"));
        } finally {
            @unlink("{$directory}/declaracion.txt");
            @unlink("{$directory}/firma.der");
            @rmdir($directory);
        }
    }
}
