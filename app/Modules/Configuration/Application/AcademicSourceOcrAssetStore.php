<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\Data\AcademicSourceOcrPage;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class AcademicSourceOcrAssetStore
{
    public function __construct(private readonly PaddleOcrDocumentConverter $converter) {}

    /** @return array{document: array<string, mixed>, image_count: int} */
    public function materialize(AcademicSource $source, JobExecution $execution, AcademicSourceOcrResult $result): array
    {
        $objects = [];
        $document = $this->converter->convert(
            $result,
            function (AcademicSourceOcrPage $page, string $imageId, string $resource) use (&$objects, $execution, $source): ?string {
                $key = $page->index.':'.$imageId;
                if (! array_key_exists($key, $objects)) {
                    $objects[$key] = $this->storeImage($source, $execution, $imageId, $resource);
                }

                return $objects[$key]?->id;
            },
        );

        return ['document' => $document, 'image_count' => count(array_filter($objects))];
    }

    private function storeImage(AcademicSource $source, JobExecution $execution, string $imageId, string $resource): ?StoredObject
    {
        [$bytes, $declaredMime] = $this->bytes($resource);
        if ($bytes === '') {
            return null;
        }
        if (strlen($bytes) > (int) config('paddleocr.max_image_bytes')) {
            throw new AcademicSourceOcrContractException('PaddleOCR devolvió una imagen demasiado grande.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: $declaredMime;
        if (! isset(self::IMAGE_EXTENSIONS[$mime])) {
            throw new AcademicSourceOcrContractException('PaddleOCR devolvió un formato de imagen no permitido.');
        }
        $hash = hash('sha256', $bytes);
        $path = "academic-sources/{$source->id}/assets/{$hash}.".self::IMAGE_EXTENSIONS[$mime];
        $disk = Storage::disk('private');
        if (! $disk->exists($path) && ! $disk->put($path, $bytes)) {
            throw new AcademicSourceOcrContractException('No se pudo guardar una imagen privada de la fuente.');
        }

        return StoredObject::query()->firstOrCreate(
            ['disco' => 'private', 'ruta_interna' => $path],
            [
                'nombre_logico' => mb_substr(basename($imageId) ?: "imagen-{$hash}", 0, 255),
                'mime' => $mime,
                'tamano_bytes' => strlen($bytes),
                'huella_sha256' => $hash,
                'clasificacion' => 'academic_source_image',
                'estado' => 'activo',
                'propietario_usuario_id' => is_string($execution->entrada['requested_by'] ?? null) ? $execution->entrada['requested_by'] : null,
                'carrera_id' => $source->carrera_id,
                'almacenado_en' => now(),
            ],
        );
    }

    /** @return array{0: string, 1: string} */
    private function bytes(string $resource): array
    {
        if (preg_match('/^data:([^;,]+);base64,(.*)$/s', trim($resource), $match) === 1) {
            $bytes = base64_decode((string) preg_replace('/\s+/', '', $match[2]), true);

            return [is_string($bytes) ? $bytes : '', strtolower($match[1])];
        }
        $parts = parse_url($resource);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            throw new AcademicSourceOcrContractException('PaddleOCR devolvió una URL de imagen inválida.');
        }
        $response = Http::withoutRedirecting()
            ->connectTimeout((int) config('paddleocr.connect_timeout_seconds'))
            ->timeout((int) config('paddleocr.timeout_seconds'))
            ->get($resource);
        if (! $response->successful()) {
            throw new AcademicSourceOcrContractException('No se pudo descargar una imagen de PaddleOCR.');
        }

        return [$response->body(), strtolower(trim(explode(';', $response->header('Content-Type'))[0]))];
    }

    /** @var array<string, string> */
    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
}
