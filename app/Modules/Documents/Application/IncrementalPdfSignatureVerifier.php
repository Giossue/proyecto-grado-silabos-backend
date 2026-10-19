<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\Data\SignerCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IncrementalPdfSignatureVerifier
{
    public function verify(string $previous, string $signed): SignerCertificate
    {
        $previousLength = strlen($previous);
        $length = strlen($signed);
        if ($length <= $previousLength || ! hash_equals($previous, substr($signed, 0, $previousLength))) {
            $this->fail('El PDF firmado no parte del documento vigente. Descárguelo de nuevo y vuelva a firmar.');
        }

        preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $signed, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $previousLength);
        if (count($matches) !== 1 || preg_match('/\/ByteRange\s*\[\s*\//', $signed, $placeholder, 0, $previousLength) === 1) {
            $this->fail('El PDF debe agregar exactamente una firma nueva.');
        }
        [$start, $firstLength, $secondStart, $secondLength] = array_map(
            fn (array $group): int => (int) $group[0],
            array_slice($matches[0], 1),
        );
        if ($start !== 0
            || $firstLength <= $previousLength
            || $secondStart <= $firstLength + 2
            || $secondStart + $secondLength !== $length
            || $signed[$firstLength] !== '<'
            || $signed[$secondStart - 1] !== '>') {
            $this->fail('La firma nueva no cubre el documento completo.');
        }
        $hex = substr($signed, $firstLength + 1, $secondStart - $firstLength - 2);
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            $this->fail('El contenido de la firma no es válido.');
        }

        return $this->verifyCms(
            $this->der((string) hex2bin($hex)),
            substr($signed, 0, $firstLength).substr($signed, $secondStart, $secondLength),
        );
    }

    private function der(string $binary): string
    {
        if (strlen($binary) < 2 || ord($binary[0]) !== 0x30) {
            $this->fail('El contenido de la firma no es un CMS válido.');
        }
        $lengthByte = ord($binary[1]);
        if ($lengthByte < 0x80) {
            return substr($binary, 0, 2 + $lengthByte);
        }
        $octets = $lengthByte & 0x7F;
        if ($octets < 1 || $octets > 4 || strlen($binary) < 2 + $octets) {
            $this->fail('El contenido de la firma no es un CMS válido.');
        }
        $bodyLength = 0;
        for ($index = 0; $index < $octets; $index++) {
            $bodyLength = ($bodyLength << 8) | ord($binary[2 + $index]);
        }
        $total = 2 + $octets + $bodyLength;
        if ($total > strlen($binary)) {
            $this->fail('El contenido de la firma está incompleto.');
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
                $this->fail('La firma no corresponde al documento: el PDF fue alterado o la firma es inválida.');
            }
            $this->assertTrustedChain($contentFile, $signatureFile, "{$directory}/cadena.pem");

            return $this->certificate((string) file_get_contents($signerFile));
        } finally {
            foreach ([$contentFile, $signatureFile, $signerFile, "{$directory}/cadena.pem"] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function assertTrustedChain(string $contentFile, string $signatureFile, string $signerFile): void
    {
        $bundle = config('signatures.trusted_ca_bundle');
        if (! is_string($bundle) || $bundle === '') {
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
        if ($trusted !== true) {
            $this->fail('El certificado de firma no fue emitido por una entidad certificadora reconocida.');
        }
    }

    private function certificate(string $pem): SignerCertificate
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $match) !== 1) {
            $this->fail('La firma no incluye el certificado del firmante.');
        }
        $parsed = openssl_x509_parse($match[0]);
        if (! is_array($parsed)) {
            $this->fail('No se pudo leer el certificado del firmante.');
        }
        $validFrom = CarbonImmutable::createFromTimestampUTC((int) ($parsed['validFrom_time_t'] ?? 0));
        $validTo = CarbonImmutable::createFromTimestampUTC((int) ($parsed['validTo_time_t'] ?? 0));
        $now = CarbonImmutable::now();
        if ($validTo->lessThan($now)) {
            $this->fail('El certificado de firma está vencido. Renueve su firma electrónica.');
        }
        if ($validFrom->greaterThan($now)) {
            $this->fail('El certificado de firma todavía no es válido.');
        }
        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $holder = $this->first($subject['CN'] ?? null);
        if ($holder === '') {
            $this->fail('El certificado de firma no identifica a su titular.');
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
        throw ValidationException::withMessages(['signed_pdf' => $message]);
    }
}
