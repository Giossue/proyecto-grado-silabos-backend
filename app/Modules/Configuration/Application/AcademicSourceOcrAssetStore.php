<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Application\Contracts\AcademicSourceFigureCropper;
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
    public function __construct(
        private readonly OpenParserDocumentConverter $converter,
        private readonly AcademicSourceFigureCropper $figureCropper,
    ) {}

    /** @return array{document: array<string, mixed>, image_count: int} */
    public function materialize(AcademicSource $source, JobExecution $execution, AcademicSourceOcrResult $result): array
    {
        $objects = [];
        $document = $this->converter->convert(
            $result,
            function (AcademicSourceOcrPage $page, string $imageId, string $resource) use (&$objects, $execution, $source): string {
                $key = $page->index.':'.$imageId;
                if (! array_key_exists($key, $objects)) {
                    $objects[$key] = $this->storeImage($source, $execution, $page, $imageId, $resource);
                }

                return $objects[$key]->id;
            },
        );

        return ['document' => $document, 'image_count' => count($objects)];
    }

    private function storeImage(
        AcademicSource $source,
        JobExecution $execution,
        AcademicSourceOcrPage $page,
        string $imageId,
        string $resource,
    ): StoredObject {
        [$bytes, $declaredMime] = $this->bytes($execution, $page, $imageId, $resource);
        if ($bytes === '') {
            throw new AcademicSourceOcrContractException('OpenParser devolvió una imagen vacía o inválida.');
        }
        if (strlen($bytes) > (int) config('openparser.max_image_bytes')) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió una imagen demasiado grande.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: $declaredMime;
        if (! isset(self::IMAGE_EXTENSIONS[$mime])) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió un formato de imagen no permitido.');
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
    private function bytes(
        JobExecution $execution,
        AcademicSourceOcrPage $page,
        string $imageId,
        string $resource,
    ): array {
        if ($resource === 'openparser-crop://'.$imageId) {
            $crops = is_array($page->metadata['figure_crops'] ?? null) ? $page->metadata['figure_crops'] : [];
            $crop = is_array($crops[$imageId] ?? null) ? $crops[$imageId] : null;
            $bbox = is_array($crop['bbox'] ?? null) ? $crop['bbox'] : null;
            $pdfObjectId = $execution->entrada['pdf_object_id'] ?? null;
            if ($crop === null || $bbox === null || ! is_string($pdfObjectId)) {
                throw new AcademicSourceOcrContractException('OpenParser no devolvió datos suficientes para recortar la figura.');
            }
            $pdf = StoredObject::query()->find($pdfObjectId);
            if ($pdf === null || $pdf->mime !== 'application/pdf') {
                throw new AcademicSourceOcrContractException('No se encontró el PDF privado para recortar la figura.');
            }

            $result = $this->figureCropper->crop(
                $pdf,
                (int) ($crop['page_number'] ?? 0),
                (float) ($crop['page_width'] ?? 0),
                (float) ($crop['page_height'] ?? 0),
                [
                    'left' => (float) ($bbox['left'] ?? -1),
                    'top' => (float) ($bbox['top'] ?? -1),
                    'right' => (float) ($bbox['right'] ?? -1),
                    'bottom' => (float) ($bbox['bottom'] ?? -1),
                ],
            );

            return [$result['bytes'], $result['mime']];
        }
        if (preg_match('/^data:([^;,]+);base64,(.*)$/s', trim($resource), $match) === 1) {
            $bytes = base64_decode((string) preg_replace('/\s+/', '', $match[2]), true);
            if (! is_string($bytes) || $bytes === '') {
                throw new AcademicSourceOcrContractException('OpenParser devolvió una imagen base64 inválida.');
            }

            return [$bytes, strtolower($match[1])];
        }
        $parts = parse_url($resource);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió una URL de imagen inválida.');
        }
        $host = strtolower($parts['host']);
        if (! in_array($host, config('openparser.allowed_asset_hosts', []), true)) {
            throw new AcademicSourceOcrContractException('OpenParser devolvió una URL de imagen de un host no permitido.');
        }
        $response = Http::withoutRedirecting()
            ->connectTimeout((int) config('openparser.connect_timeout_seconds'))
            ->timeout((int) config('openparser.timeout_seconds'))
            ->get($resource);
        if (! $response->successful()) {
            throw new AcademicSourceOcrContractException('No se pudo descargar una imagen de OpenParser.');
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
