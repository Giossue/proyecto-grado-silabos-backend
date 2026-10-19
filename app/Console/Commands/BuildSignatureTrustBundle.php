<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildSignatureTrustBundle extends Command
{
    protected $signature = 'signatures:build-trust-bundle {files* : Certificados raíz o intermedios (.cer, .crt o .pem)} {--output=storage/app/private/ca-confiables.pem}';

    protected $description = 'Arma el archivo de entidades certificadoras de confianza para validar las firmas electrónicas';

    public function handle(): int
    {
        $bundle = [];
        $rows = [];
        $failed = false;
        foreach ((array) $this->argument('files') as $file) {
            $pem = $this->toPem((string) $file);
            $parsed = $pem === null ? false : openssl_x509_parse($pem);
            if ($pem === null || ! is_array($parsed)) {
                $this->error("{$file}: no es un certificado válido.");
                $failed = true;

                continue;
            }
            $extensions = is_array($parsed['extensions'] ?? null) ? $parsed['extensions'] : [];
            $basic = is_string($extensions['basicConstraints'] ?? null) ? $extensions['basicConstraints'] : '';
            $selfSignedV1 = $basic === '' && ($parsed['version'] ?? null) === 0 && ($parsed['subject'] ?? null) === ($parsed['issuer'] ?? false);
            if (! str_contains($basic, 'CA:TRUE') && ! $selfSignedV1) {
                $this->error("{$file}: no es de una entidad certificadora (no es CA).");
                $failed = true;

                continue;
            }
            $validTo = CarbonImmutable::createFromTimestampUTC((int) ($parsed['validTo_time_t'] ?? 0));
            if ($validTo->isPast()) {
                $this->error("{$file}: el certificado está vencido ({$validTo->toDateString()}).");
                $failed = true;

                continue;
            }
            $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
            $bundle[] = trim($pem)."\n";
            $rows[] = [
                basename((string) $file),
                is_scalar($subject['CN'] ?? null) ? (string) $subject['CN'] : '—',
                strtoupper((string) openssl_x509_fingerprint($pem, 'sha256')),
                $validTo->toDateString(),
            ];
        }
        if ($failed || $bundle === []) {
            return self::FAILURE;
        }
        $requested = (string) $this->option('output');
        $output = str_starts_with($requested, '/') ? $requested : base_path($requested);
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }
        file_put_contents($output, implode("\n", $bundle));
        $this->table(['Archivo', 'Entidad', 'Huella SHA-256', 'Vence'], $rows);
        $this->info('Archivo escrito en '.$output);
        $this->line('Compare cada huella con la que publica la entidad y use esa ruta en SIGNATURE_TRUSTED_CA_BUNDLE.');

        return self::SUCCESS;
    }

    private function toPem(string $file): ?string
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            return null;
        }
        if (str_contains($contents, '-----BEGIN CERTIFICATE-----')) {
            return $contents;
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($contents), 64, "\n")."-----END CERTIFICATE-----\n";
    }
}
