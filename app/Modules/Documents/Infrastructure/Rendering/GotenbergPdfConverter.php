<?php

namespace App\Modules\Documents\Infrastructure\Rendering;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GotenbergPdfConverter
{
    public function enabled(): bool
    {
        return $this->url() !== '';
    }

    public function convert(string $docx, string $generatedAt): string
    {
        try {
            $response = Http::timeout((int) config('services.gotenberg.timeout', 90))
                ->attach('files', $docx, 'silabo.docx')
                ->post($this->url().'/forms/libreoffice/convert');
        } catch (Throwable $exception) {
            throw new RuntimeException('El servicio de conversión a PDF no respondió.', previous: $exception);
        }
        $pdf = $response->body();
        if (! $response->successful() || ! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException("El servicio de conversión a PDF respondió {$response->status()}.");
        }

        return $this->stabilize($pdf, $docx, $generatedAt);
    }

    public function convertFile(string $content, string $filename): string
    {
        try {
            $response = Http::timeout((int) config('services.gotenberg.timeout', 90))
                ->attach('files', $content, $filename)
                ->post($this->url().'/forms/libreoffice/convert');
        } catch (Throwable $exception) {
            throw new RuntimeException('El servicio de conversión a PDF no respondió.', previous: $exception);
        }
        $pdf = $response->body();
        if (! $response->successful() || ! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException("El servicio de conversión a PDF respondió {$response->status()}.");
        }

        return $pdf;
    }

    public function stabilize(string $pdf, string $docx, string $generatedAt): string
    {
        $timestamp = strtotime($generatedAt);
        $date = 'D:'.gmdate('YmdHis', $timestamp === false ? 0 : $timestamp);
        $pdf = (string) preg_replace_callback(
            '/\/(CreationDate|ModDate)\s*\((D:[0-9]{14}[^)]*)\)/',
            fn (array $match): string => '/'.$match[1].'('.str_pad($date."Z00'00'", strlen($match[2]), ' ').')',
            $pdf,
        );
        $id = md5($docx);

        return (string) preg_replace_callback(
            '/\/ID\s*\[\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\]/',
            fn (array $match): string => str_replace(
                [$match[1], $match[2]],
                [$this->fit($id, strlen($match[1])), $this->fit($id, strlen($match[2]))],
                $match[0],
            ),
            $pdf,
        );
    }

    private function fit(string $hex, int $length): string
    {
        return substr(str_repeat(strtoupper($hex), (int) ceil($length / 32) + 1), 0, $length);
    }

    private function url(): string
    {
        return rtrim((string) config('services.gotenberg.url', ''), '/');
    }
}
