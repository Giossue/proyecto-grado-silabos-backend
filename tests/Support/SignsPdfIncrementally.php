<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

trait SignsPdfIncrementally
{
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

    /** @return array{certificate: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string} */
    private function makeCertificateAuthority(string $name = 'ENTIDAD CERTIFICADORA DE PRUEBA'): array
    {
        $config = $this->opensslConfig('[req]'."\n".'distinguished_name=dn'."\n".'[dn]'."\n".'[v3_ca]'."\n".'basicConstraints=critical,CA:TRUE'."\n".'keyUsage=critical,keyCertSign,cRLSign'."\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $request = openssl_csr_new(['countryName' => 'EC', 'commonName' => $name], $key, ['digest_alg' => 'sha256', 'config' => $config]);
        $certificate = openssl_csr_sign($request, null, $key, 365, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_ca'], random_int(1000, 999999));
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
    private function signPdfIncrementally(string $pdf, array $credentials, string $contentsHexLength = '32768'): string
    {
        $placeholder = str_repeat('0', (int) $contentsHexLength);
        $object = 9000 + substr_count($pdf, '/ByteRange');
        $increment = "\n{$object} 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached "
            .'/ByteRange [0 /********** /********** /**********]'
            ." /Contents <{$placeholder}> /M (D:20260924000000Z) >>\nendobj\n%%EOF\n";
        $document = $pdf.$increment;

        $contentsStart = strrpos($document, "/Contents <{$placeholder}>");
        if ($contentsStart === false) {
            throw new RuntimeException('No se encontró el marcador de la firma.');
        }
        $first = $contentsStart + strlen('/Contents ');
        $second = $first + strlen($placeholder) + 2;
        $range = sprintf('/ByteRange [0 %d %d %d]', $first, $second, strlen($document) - $second);
        $document = str_replace('/ByteRange [0 /********** /********** /**********]', str_pad($range, strlen('/ByteRange [0 /********** /********** /**********]')), $document);

        $signedContent = substr($document, 0, $first).substr($document, $second);
        $directory = sys_get_temp_dir().'/silabos-prueba-firma-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents("{$directory}/contenido.bin", $signedContent);
            $ok = openssl_cms_sign(
                "{$directory}/contenido.bin",
                "{$directory}/firma.der",
                $credentials['certificate'],
                $credentials['key'],
                [],
                OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                OPENSSL_ENCODING_DER,
            );
            if (! $ok) {
                throw new RuntimeException('No se pudo firmar el PDF de prueba.');
            }
            $hex = bin2hex((string) file_get_contents("{$directory}/firma.der"));
        } finally {
            @unlink("{$directory}/contenido.bin");
            @unlink("{$directory}/firma.der");
            @rmdir($directory);
        }

        return substr($document, 0, $first + 1).str_pad($hex, strlen($placeholder), '0').substr($document, $second - 1);
    }
}
