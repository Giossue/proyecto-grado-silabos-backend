<?php

namespace App\Modules\Configuration\Infrastructure\Documents;

use App\Modules\Configuration\Application\Contracts\AcademicSourceFigureCropper;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

final class PopplerAcademicSourceFigureCropper implements AcademicSourceFigureCropper
{
    public function crop(
        StoredObject $pdf,
        int $pageNumber,
        float $sourcePageWidth,
        float $sourcePageHeight,
        array $bbox,
    ): array {
        if ($pageNumber < 1 || $sourcePageWidth <= 0 || $sourcePageHeight <= 0) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió coordenadas de figura inválidas.');
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecrop')) {
            throw new AcademicSourceOcrContractException('El servidor no puede recortar las figuras del PDF.');
        }

        $pdfPath = tempnam(sys_get_temp_dir(), 'silabos-pdf-');
        $outputPrefix = tempnam(sys_get_temp_dir(), 'silabos-figure-');
        if ($pdfPath === false || $outputPrefix === false) {
            if (is_string($pdfPath)) {
                @unlink($pdfPath);
            }
            if (is_string($outputPrefix)) {
                @unlink($outputPrefix);
            }
            throw new AcademicSourceOcrContractException('No se pudo preparar el recorte privado de la figura.');
        }
        @unlink($outputPrefix);
        $pngPath = $outputPrefix.'.png';

        try {
            $input = Storage::disk($pdf->disco)->readStream($pdf->ruta_interna);
            $target = fopen($pdfPath, 'wb');
            if (! is_resource($input) || ! is_resource($target)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new AcademicSourceOcrContractException('No se pudo leer el PDF privado para recortar la figura.');
            }
            try {
                stream_copy_to_stream($input, $target);
            } finally {
                fclose($input);
                fclose($target);
            }

            $process = new Process([
                'pdftoppm',
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-singlefile',
                '-png',
                '-r', (string) max(72, (int) config('openparser.figure_render_dpi')),
                $pdfPath,
                $outputPrefix,
            ]);
            $process->setTimeout(max(5, (int) config('openparser.figure_render_timeout_seconds')));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($pngPath)) {
                throw new AcademicSourceOcrContractException('No se pudo renderizar la página que contiene la figura.');
            }

            $pageBytes = file_get_contents($pngPath);
            $pageImage = is_string($pageBytes) ? imagecreatefromstring($pageBytes) : false;
            if ($pageImage === false) {
                throw new AcademicSourceOcrContractException('La página renderizada no es una imagen válida.');
            }
            try {
                $scaleX = imagesx($pageImage) / $sourcePageWidth;
                $scaleY = imagesy($pageImage) / $sourcePageHeight;
                $x = max(0, (int) floor($bbox['left'] * $scaleX));
                $y = max(0, (int) floor($bbox['top'] * $scaleY));
                $width = min(imagesx($pageImage) - $x, max(1, (int) ceil(($bbox['right'] - $bbox['left']) * $scaleX)));
                $height = min(imagesy($pageImage) - $y, max(1, (int) ceil(($bbox['bottom'] - $bbox['top']) * $scaleY)));
                if ($width < 1 || $height < 1) {
                    throw new AcademicSourceOcrContractException('La figura queda fuera de los límites de la página.');
                }
                $cropped = imagecrop($pageImage, compact('x', 'y', 'width', 'height'));
                if ($cropped === false) {
                    throw new AcademicSourceOcrContractException('No se pudo recortar la figura detectada.');
                }
                try {
                    ob_start();
                    imagepng($cropped, null, 6);
                    $bytes = ob_get_clean();
                } finally {
                    imagedestroy($cropped);
                }
            } finally {
                imagedestroy($pageImage);
            }
            if (! is_string($bytes) || $bytes === '') {
                throw new AcademicSourceOcrContractException('El recorte de la figura quedó vacío.');
            }

            return ['bytes' => $bytes, 'mime' => 'image/png'];
        } finally {
            @unlink($pdfPath);
            @unlink($pngPath);
        }
    }
}
