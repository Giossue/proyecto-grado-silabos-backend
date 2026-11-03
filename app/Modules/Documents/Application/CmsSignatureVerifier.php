<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\Data\SignerCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CmsSignatureVerifier
{
    private const SIGNING_KEY_USAGES = ['Digital Signature', 'Non Repudiation'];

    // Igual que la comprobación S/MIME de OpenSSL: «Any Extended Key Usage» no basta para firmar.
    private const SIGNING_EXTENDED_KEY_USAGES = ['E-mail Protection'];

    public function verify(string $content, string $signature): SignerCertificate
    {
        $encoded = preg_replace('/-----(BEGIN|END) [A-Z0-9 ]+-----|\s+/', '', $signature) ?? '';
        $binary = base64_decode($encoded, true);
        if ($encoded === '' || $binary === false) {
            $this->fail('La firma no es válida.');
        }

        return $this->verifyCms($this->der($binary), $content);
    }

    private function der(string $binary): string
    {
        if (strlen($binary) < 2 || ord($binary[0]) !== 0x30) {
            $this->fail('La firma no es válida.');
        }
        $lengthByte = ord($binary[1]);
        if ($lengthByte < 0x80) {
            return substr($binary, 0, 2 + $lengthByte);
        }
        $octets = $lengthByte & 0x7F;
        if ($octets < 1 || $octets > 4 || strlen($binary) < 2 + $octets) {
            $this->fail('La firma no es válida.');
        }
        $bodyLength = 0;
        for ($index = 0; $index < $octets; $index++) {
            $bodyLength = ($bodyLength << 8) | ord($binary[2 + $index]);
        }
        $total = 2 + $octets + $bodyLength;
        if ($total > strlen($binary)) {
            $this->fail('La firma está incompleta.');
        }

        return substr($binary, 0, $total);
    }

    private function verifyCms(string $der, string $content): SignerCertificate
    {
        $directory = sys_get_temp_dir().'/silabos-firma-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar la verificación de la firma.');
        }
        $contentFile = "{$directory}/contenido.bin";
        $signatureFile = "{$directory}/firma.der";
        $signerFile = "{$directory}/firmante.pem";
        try {
            file_put_contents($contentFile, $content);
            file_put_contents($signatureFile, $der);
            while (openssl_error_string() !== false) {
            }
            $valid = openssl_cms_verify(
                $contentFile,
                OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_DETACHED,
                $signerFile,
                [],
                null,
                null,
                null,
                $signatureFile,
                OPENSSL_ENCODING_DER,
            );
            if ($valid !== true || ! is_file($signerFile)) {
                $this->fail('La firma no corresponde a la declaración.');
            }
            $signerPem = (string) file_get_contents($signerFile);
            $this->assertTrustedChain($contentFile, $signatureFile, "{$directory}/cadena.pem", $signerPem);

            return $this->certificate($signerPem);
        } finally {
            foreach ([$contentFile, $signatureFile, $signerFile, "{$directory}/cadena.pem"] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function assertTrustedChain(string $contentFile, string $signatureFile, string $signerFile, string $signerPem): void
    {
        $bundle = config('signatures.trusted_ca_bundle');
        $configured = is_string($bundle) && $bundle !== '';
        if (app()->environment('production') && (! $configured || ! is_file($bundle))) {
            $this->fail('No se puede verificar la firma: falta configurar las entidades certificadoras de confianza. Avise a Administración.');
        }
        if (! $configured) {
            return;
        }
        if (! is_file($bundle)) {
            throw new RuntimeException('No se encontró el archivo de entidades certificadoras de confianza.');
        }
        while (openssl_error_string() !== false) {
        }
        $trusted = openssl_cms_verify(
            $contentFile,
            OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
            $signerFile,
            [$bundle],
            null,
            null,
            null,
            $signatureFile,
            OPENSSL_ENCODING_DER,
        );
        if ($trusted !== true && ! $this->issuedByListedAuthority($signerPem, $bundle)) {
            $this->fail('El certificado no es de una entidad reconocida.');
        }
    }

    private function issuedByListedAuthority(string $signerPem, string $bundle): bool
    {
        $signer = openssl_x509_parse($signerPem);
        if (! is_array($signer) || ! $this->isSigningCertificate($signer)) {
            return false;
        }
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($bundle), $matches);
        foreach ($matches[0] as $authorityPem) {
            $authority = openssl_x509_parse($authorityPem);
            $key = openssl_pkey_get_public($authorityPem);
            if (! is_array($authority) || $key === false) {
                continue;
            }
            if (($authority['subject'] ?? null) === ($signer['issuer'] ?? false)
                && $this->isAuthority($authority)
                && (int) ($authority['validFrom_time_t'] ?? PHP_INT_MAX) <= time()
                && (int) ($authority['validTo_time_t'] ?? 0) > time()
                && openssl_x509_verify($signerPem, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors OpenSSL's S/MIME signing purpose, which the strict check applies, so the
     * listed-authority fallback never accepts a certificate the strict check rejected by purpose.
     *
     * @param  array<string, mixed>  $certificate
     */
    private function isSigningCertificate(array $certificate): bool
    {
        $extensions = is_array($certificate['extensions'] ?? null) ? $certificate['extensions'] : [];
        $keyUsage = $this->extensionValues($extensions['keyUsage'] ?? null);
        if ($keyUsage !== null && array_intersect($keyUsage, self::SIGNING_KEY_USAGES) === []) {
            return false;
        }
        $extendedKeyUsage = $this->extensionValues($extensions['extendedKeyUsage'] ?? null);

        return $extendedKeyUsage === null || array_intersect($extendedKeyUsage, self::SIGNING_EXTENDED_KEY_USAGES) !== [];
    }

    /** @param array<string, mixed> $certificate */
    private function isAuthority(array $certificate): bool
    {
        $extensions = is_array($certificate['extensions'] ?? null) ? $certificate['extensions'] : [];

        return in_array('CA:TRUE', $this->extensionValues($extensions['basicConstraints'] ?? null) ?? [], true);
    }

    /** @return list<string>|null */
    private function extensionValues(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''));
    }

    private function certificate(string $pem): SignerCertificate
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $match) !== 1) {
            $this->fail('La firma no incluye el certificado.');
        }
        $parsed = openssl_x509_parse($match[0]);
        if (! is_array($parsed)) {
            $this->fail('No se pudo leer el certificado.');
        }
        $validFrom = CarbonImmutable::createFromTimestampUTC((int) ($parsed['validFrom_time_t'] ?? 0));
        $validTo = CarbonImmutable::createFromTimestampUTC((int) ($parsed['validTo_time_t'] ?? 0));
        $now = CarbonImmutable::now();
        if ($validTo->lessThan($now)) {
            $this->fail('El certificado está vencido.');
        }
        if ($validFrom->greaterThan($now)) {
            $this->fail('El certificado todavía no es válido.');
        }
        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $holder = $this->first($subject['CN'] ?? null);
        if ($holder === '') {
            $this->fail('El certificado no identifica a su titular.');
        }

        return new SignerCertificate(
            holder: mb_substr($holder, 0, 255),
            serial: mb_substr((string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''), 0, 255),
            issuer: mb_substr($this->distinguishedName(is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : []), 0, 500),
            validTo: $validTo,
        );
    }

    /** @param array<string, mixed> $parts */
    private function distinguishedName(array $parts): string
    {
        $pairs = [];
        foreach ($parts as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = "{$key}=".(is_scalar($item) ? (string) $item : '');
            }
        }

        return implode(', ', $pairs);
    }

    private function first(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['signature' => $message]);
    }
}
