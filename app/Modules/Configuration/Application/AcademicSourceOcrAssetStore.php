<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Domain\Exceptions\AcademicSourceOcrContractException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSourceExtraction;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Illuminate\Support\Facades\Storage;

final class AcademicSourceOcrAssetStore
{
    /**
     * @return array{markdown: string, image_count: int}
     */
    public function materialize(
        AcademicSource $source,
        AcademicSourceExtraction $extraction,
        AcademicSourceOcrResult $result,
    ): array {
        $objects = [];

        $markdown = $result->markdown(function (string $imageId, array $image) use (&$objects, $source, $extraction): ?string {
            if (! array_key_exists($imageId, $objects)) {
                $objects[$imageId] = $this->storeImage($source, $extraction, $imageId, $image);
            }

            $object = $objects[$imageId];

            return $object instanceof StoredObject
                ? route('api.v1.coordination.sources.assets.show', [
                    'source' => $source->id,
                    'asset' => $object->id,
                ])
                : null;
        });

        return [
            'markdown' => $markdown,
            'image_count' => count(array_filter($objects, static fn (mixed $object): bool => $object instanceof StoredObject)),
        ];
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function storeImage(
        AcademicSource $source,
        AcademicSourceExtraction $extraction,
        string $imageId,
        array $image,
    ): ?StoredObject {
        $encoded = $image['image_base64'] ?? $image['image_data'] ?? null;
        if (! is_string($encoded) || trim($encoded) === '') {
            return null;
        }

        [$mime, $encoded] = $this->splitDataUrl($encoded, $image);
        $bytes = base64_decode((string) preg_replace('/\s+/', '', $encoded), true);
        if (! is_string($bytes) || $bytes === '') {
            throw new AcademicSourceOcrContractException('Mistral OCR devolvió una imagen inválida.');
        }

        $maxBytes = (int) config('mistral.max_image_bytes');
        if ($maxBytes > 0 && strlen($bytes) > $maxBytes) {
            throw new AcademicSourceOcrContractException('Mistral OCR devolvió una imagen demasiado grande.');
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (isset(self::IMAGE_EXTENSIONS[$detectedMime])) {
            $mime = $detectedMime;
        }
        if (! isset(self::IMAGE_EXTENSIONS[$mime])) {
            throw new AcademicSourceOcrContractException('Mistral OCR devolvió un formato de imagen no permitido.');
        }

        $hash = hash('sha256', $bytes);
        $extension = self::IMAGE_EXTENSIONS[$mime];
        $path = "academic-sources/{$source->id}/assets/{$hash}.{$extension}";
        $disk = Storage::disk('private');
        if (! $disk->exists($path) && ! $disk->put($path, $bytes)) {
            throw new AcademicSourceOcrContractException('No se pudo guardar una imagen OCR privada.');
        }

        $object = StoredObject::query()->firstOrCreate(
            ['disco' => 'private', 'ruta_interna' => $path],
            [
                'nombre_logico' => basename($imageId),
                'mime' => $mime,
                'tamano_bytes' => strlen($bytes),
                'huella_sha256' => $hash,
                'clasificacion' => 'academic_source_image',
                'estado' => 'activo',
                'propietario_usuario_id' => $extraction->solicitada_por,
                'carrera_id' => $source->carrera_id,
                'almacenado_en' => now(),
            ],
        );

        return $object;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array{0: string, 1: string}
     */
    private function splitDataUrl(string $encoded, array $image): array
    {
        $mime = strtolower(trim((string) ($image['mime_type'] ?? $image['mime'] ?? '')));
        if (preg_match('/^data:([^;,]+);base64,(.*)$/s', trim($encoded), $matches) === 1) {
            $mime = strtolower($matches[1]);
            $encoded = $matches[2];
        }

        return [$mime, $encoded];
    }

    /** @var array<string, string> */
    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
}
